# Reste à faire — mise en production

> Document **temporaire** : à supprimer une fois le simulateur en ligne.
> État au 2026-08-24. La procédure détaillée est dans
> [`exploitation.md`](exploitation.md) ; ceci en est la liste de contrôle.

## Ce qui est déjà fait

- Lots 1 à 3 : API de zonage, widget embarquable, redirection vers le
  formulaire de devis du partenaire. 58 tests.
- Millésime 2026 chargé et vérifié **en local**.
- Pile de production **répétée** hors VPS : image construite, migrations
  jouées, zonage chargé par le chemin `--prod`, sauvegarde et **restauration
  jouées**.
- DNS `api.zonage.sallakane.cloud` → VPS : en place.
- Côté Atlantis : *prepopulate* activé sur `rue`, `code_postal`, `ville`,
  `description_de_la_demande`, et champ caché `simulation_id` ajouté. Vérifié
  contre le formulaire en ligne.

---

## Sur le VPS

### 1. Avant tout — vérifier

```bash
df -h /var/lib/docker           # ~2 Go libres nécessaires
ss -tlnp | grep 127.0.0.1:80    # 8085 doit être LIBRE
docker ps                        # voisins : rapport-generator, sunu-cagnotte, BCEAO, Keycloak, Maison Brute
```

Si 8085 est pris, ne pas improviser : changer `APP_HTTP_PORT` **et** le bloc
Caddy ensemble, et le noter dans `infra/Caddyfile.snippet`.

### 2. Le code et les secrets

```bash
sudo git clone git@github.com:sallakane/simulator-geo.git /var/www/simulateur
sudo chown -R deploy:deploy /var/www/simulateur
cd /var/www/simulateur
cp .env.local.example .env.local && chmod 600 .env.local
$EDITOR .env.local
```

À renseigner, **avant tout démarrage** :

| Variable | Valeur |
|---|---|
| `APP_SECRET` | `openssl rand -hex 32` |
| `POSTGRES_PASSWORD` | `openssl rand -hex 24` — lu **uniquement** à l'initialisation du volume |
| `DATABASE_URL` | reprendre le même mot de passe |
| `APP_HTTP_PORT` | `8085` |
| `DEFAULT_URI` | `https://api.zonage.sallakane.cloud` |
| `MAILER_DSN` | `null://null` suffit : plus aucun e-mail n'est envoyé depuis le lot 3 |

> ⚠️ **Ne jamais lancer `docker compose up` à la main.** Compose n'interpole
> pas `.env.local` : le volume Postgres naîtrait avec le mot de passe par
> défaut, et l'application ne pourrait plus s'y connecter. Seul systemd fournit
> l'environnement. Symptôme et rattrapage : `exploitation.md` §5.

### 3. Démarrer

```bash
sudo cp infra/simulateur.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now simulateur

docker compose -f compose.prod.yaml ps          # app + database « healthy »
curl -s localhost:8085/api/v1/health            # 503 attendu : pas encore de zonage
```

### 4. Le zonage (~420 Mo, à télécharger depuis le VPS, pas depuis un poste)

```bash
cd /var/www/simulateur/data
curl -O https://files.georisques.fr/argiles/2025/AleaRG_2025_Fxx_L93.zip
sha256sum AleaRG_2025_Fxx_L93.zip
# attendu : 18e9504365fd390e4d6db1784a89f8ca85ae3a15b5e289637b23f77162bdce87
unzip AleaRG_2025_Fxx_L93.zip && cd ..

./bin/charger-rga.sh --prod --millesime 2026 \
    --shp data/AleaRG_2025_Fxx_L93.shp --encoding UTF-8
```

Compter ~35 s de chargement puis plusieurs minutes de `ST_MakeValid`. Le script
**refuse de basculer** si le nombre de polygones diffère du shapefile.
Attendu : 121 399 polygones — 35 091 / 64 039 / 22 269 en niveaux 1 / 2 / 3,
Corse > 0, Paris = 35 (enveloppe, pas la ville).

```bash
./bin/charger-rga.sh --prod --millesime 2026 --bascule
curl -s localhost:8085/api/v1/health     # {"status":"ok","rga_millesime":"2026","polygones":121399}
docker compose -f compose.prod.yaml exec app php bin/console app:zonage:verifier
```

> ⚠️ Ne pas garder le `.zip` **et** le `.shp` si la place manque : le `.shp`
> seul suffit une fois chargé, et tout est rechargeable depuis l'URL ci-dessus.

### 5. Le partenaire

```bash
docker compose -f compose.prod.yaml exec app php bin/console app:partner:create \
    "Atlantis Géotechnique" \
    --origine https://atlantis-geotechnique.fr \
    --origine 'https://*.atlantis-geotechnique.fr' \
    --formulaire https://atlantis-geotechnique.fr/demande-devis \
    -c rue=rue -c code_postal=code_postal -c ville=ville \
    -c message=description_de_la_demande -c simulation=simulation_id
```

**Noter la clé publique affichée** : elle va dans le `<script>` du site hôte.

### 6. Le domaine

```bash
sudo cp /etc/caddy/Caddyfile /etc/caddy/Caddyfile.bak.$(date +%F-%H%M%S)
sudo $EDITOR /etc/caddy/Caddyfile                    # coller infra/Caddyfile.snippet
sudo caddy validate --config /etc/caddy/Caddyfile    # VALIDER avant
sudo systemctl reload caddy                          # reload, pas restart
curl -s https://api.zonage.sallakane.cloud/api/v1/health
```

Le Caddyfile est partagé avec `maisonbrute.fr`, `ag-rapport-generator.fr` et
`sunu-cagnotte.org`. Une erreur de syntaxe les coupe tous.

### 7. La sauvegarde

```bash
sudo mkdir -p /var/backups/simulateur && sudo chown deploy:deploy /var/backups/simulateur
sudo touch /var/log/simulateur-backup.log && sudo chown deploy:deploy /var/log/simulateur-backup.log
sudo cp infra/simulateur-backup.sh /usr/local/bin/simulateur-backup && sudo chmod +x /usr/local/bin/simulateur-backup
sudo cp infra/simulateur-backup.cron /etc/cron.d/simulateur-backup
/usr/local/bin/simulateur-backup     # la lancer UNE FOIS à la main
```

---

## Sur le site d'Atlantis

Bloc à insérer là où le simulateur doit apparaître. Le contenu du `<div>` est le
**repli statique** : il reste affiché si le simulateur ne répond pas, et c'est
volontaire.

```html
<div id="zonage-widget">
  <p>Vous vendez un terrain ou vous construisez ?
     <a href="/demande-devis">Demandez votre devis d’étude de sol</a>.</p>
</div>
<script src="https://api.zonage.sallakane.cloud/widget.js?key=LA_CLE_PUBLIQUE" async></script>
```

Recette à faire sur leur page, dans cet ordre :

1. saisir une adresse, choisir une proposition, vérifier le verdict ;
2. cliquer l'appel à l'action → le formulaire de devis s'ouvre **dans le même
   onglet**, avec rue / code postal / ville / description pré-remplis ;
3. `sudo systemctl stop simulateur`, recharger leur page : **le repli statique
   doit rester visible et le site rester utilisable**. Puis redémarrer.

---

## À dire à Atlantis avant la mise en ligne

1. **L'adresse du terrain passe en clair dans l'URL** de leur formulaire : elle
   apparaîtra dans leurs journaux serveur et leur analytics. C'est leur domaine
   et leur responsabilité, mais ils doivent le savoir.
2. **Le pré-remplissage est une dépendance silencieuse.** S'ils désactivent le
   *prepopulate* d'un champ, la redirection continuera de fonctionner mais le
   formulaire arrivera vide, sans aucune erreur visible côté simulateur.
3. **Le texte pré-rempli est modifiable par le visiteur.** Le champ caché
   `simulation_id` ne l'est pas : c'est lui qui fait foi.

## Ce qui reste ouvert, après la mise en ligne

- **VPS dans l'UE** : à confirmer (SPEC §9). La table `simulation` y vit.
- **Tableau de bord de conversion** (SPEC §14, lot 3) : pas commencé. À bâtir
  sur ce qu'on mesure honnêtement — des clics, pas des envois.
- **Valeurs client encore absentes** (SPEC §16) : prix ou fourchettes par
  mission, délai réellement tenable, périmètre géographique d'intervention.
  Rien de tout cela n'est inventé dans le code aujourd'hui.
