// Carte des mesures (onglet « Mesures » de /auth). Lit les points agrégés par le serveur dans
// <script type="application/json" id="map-points"> et les place sur une carte Leaflet.
// Aucune donnée n'est insérée en HTML : les libellés passent par textContent.
(function () {
  'use strict';
  var container = document.getElementById('map');
  var source = document.getElementById('map-points');
  if (!container || !source || !window.L) return;

  var points = [];
  try { points = JSON.parse(source.textContent || '[]'); } catch (e) { points = []; }
  if (!Array.isArray(points) || !points.length) return;

  var map = L.map(container, { worldCopyJump: true }).setView([46.5, 2.2], 4);
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
  }).addTo(map);

  // Même code couleur que l'ancienne page : 1-2 requêtes bleu, 3-5 orange, 6 et plus rouge.
  function colorClass(count) {
    if (count >= 6) return 'c3';
    if (count >= 3) return 'c2';
    return 'c1';
  }

  var bounds = [];
  points.forEach(function (p) {
    var lat = Number(p.lat);
    var lon = Number(p.lon);
    var count = Number(p.count) || 0;
    if (!isFinite(lat) || !isFinite(lon)) return;

    var icon = L.divIcon({
      className: '',
      html: '<div class="mk ' + colorClass(count) + '"></div>',
      iconSize: [30, 30],
      iconAnchor: [15, 15]
    });
    var marker = L.marker([lat, lon], { icon: icon }).addTo(map);
    // le nombre est écrit via textContent (aucune donnée du serveur n'est interprétée comme du HTML)
    var el = marker.getElement();
    if (el && el.firstChild) el.firstChild.textContent = String(count);

    var popup = document.createElement('div');
    var title = document.createElement('strong');
    title.textContent = p.label || 'Lieu inconnu';
    popup.appendChild(title);
    popup.appendChild(document.createElement('br'));
    popup.appendChild(document.createTextNode(count + (count > 1 ? ' requêtes' : ' requête')));
    marker.bindPopup(popup);
    bounds.push([lat, lon]);
  });

  if (bounds.length) map.fitBounds(bounds, { padding: [30, 30], maxZoom: 6 });
})();
