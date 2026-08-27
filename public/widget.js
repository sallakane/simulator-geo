/**
 * Chargeur du simulateur RGA — SPEC §7.
 *
 * Ce fichier s'exécute sur des sites tiers dont on ne maîtrise rien : pas de
 * dépendance, pas de framework, pas de cookie, pas de traceur. Il doit pouvoir
 * tourner avant tout consentement.
 *
 * Règle non négociable : **on ne vide jamais le conteneur**. Le repli statique
 * que le site hôte a écrit à l'intérieur reste en place tant que le simulateur
 * n'a pas prouvé qu'il fonctionne. Si l'API tombe, si le script échoue, si le
 * réseau lâche, le site hôte continue de convertir sans nous.
 */
(function () {
  'use strict';

  var DELAI_MAX_MS = 3000; // Au-delà, on considère que le simulateur ne vient pas.

  var script = document.currentScript || (function () {
    var tous = document.getElementsByTagName('script');
    return tous[tous.length - 1];
  })();
  if (!script || !script.src) { return; }

  var source;
  try { source = new URL(script.src); } catch (e) { return; }

  var cle = source.searchParams.get('key');
  if (!cle) { return; }

  // Seul réglage transmis à l'iframe : `intro=0` coupe l'introduction
  // pédagogique, pour une intégration en colonne étroite ou sous un texte qui
  // dit déjà la même chose. Rien d'autre ne passe par ici — le reste de
  // l'apparence vient de `partner.theme`, côté serveur, pas d'un attribut que
  // n'importe qui pourrait éditer dans la page hôte.
  var intro = source.searchParams.get('intro');

  // L'URL de base se déduit de l'endroit d'où ce script a été chargé : le
  // domaine changera, et il ne doit être écrit en dur nulle part (SPEC §1).
  var origine = source.origin;

  function demarrer() {
    var conteneur = document.getElementById('zonage-widget')
      || document.querySelector('[data-zonage-widget]');
    if (!conteneur || conteneur.getAttribute('data-zonage-monte')) { return; }
    conteneur.setAttribute('data-zonage-monte', '1');

    // Le repli reste dans le DOM : on le masquera seulement quand le
    // simulateur aura signalé qu'il est prêt.
    var repli = [];
    for (var i = 0; i < conteneur.children.length; i++) { repli.push(conteneur.children[i]); }

    var cadre = document.createElement('iframe');
    cadre.src = origine + '/embed?key=' + encodeURIComponent(cle)
      + ('0' === intro ? '&intro=0' : '');
    cadre.title = 'Simulateur d’exposition au retrait-gonflement des argiles';
    cadre.setAttribute('scrolling', 'no');
    cadre.setAttribute('allowtransparency', 'true');
    // Isolation CSS totale : aucun conflit possible avec la feuille de style du
    // site hôte, et un seul point de mise à jour pour tout le parc (SPEC §7).
    cadre.style.cssText = 'display:block;width:100%;height:0;border:0;overflow:hidden;background:transparent';
    conteneur.appendChild(cadre);

    var pret = false;

    var minuteur = setTimeout(function () {
      if (pret) { return; }
      // Rien reçu : on retire l'iframe et on laisse la page telle qu'elle
      // était. Mieux vaut pas de simulateur qu'un cadre vide.
      if (cadre.parentNode) { cadre.parentNode.removeChild(cadre); }
    }, DELAI_MAX_MS);

    window.addEventListener('message', function (evenement) {
      // N'accepter que ce qui vient de NOTRE iframe : une page hôte peut
      // contenir d'autres cadres, et n'importe qui peut poster un message.
      if (evenement.origin !== origine || evenement.source !== cadre.contentWindow) { return; }

      var message = evenement.data;
      if (!message || typeof message !== 'object') { return; }

      if (message.type === 'zonage:pret') {
        pret = true;
        clearTimeout(minuteur);
        for (var j = 0; j < repli.length; j++) { repli[j].style.display = 'none'; }
      }

      if (message.type === 'zonage:hauteur' && typeof message.hauteur === 'number') {
        cadre.style.height = Math.max(0, Math.round(message.hauteur)) + 'px';
      }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', demarrer);
  } else {
    demarrer();
  }
})();
