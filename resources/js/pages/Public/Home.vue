<script setup lang="ts">
import { onMounted, onUnmounted, ref } from 'vue'
import L from 'leaflet'
import 'leaflet/dist/leaflet.css'
import '../../../css/map.css'

import { union } from '@turf/union'
import { difference } from '@turf/difference'
import { polygon, featureCollection } from '@turf/helpers'
//import { Head, Link } from '@inertiajs/vue3';

import type {
  FeatureCollection,
  Polygon,
  MultiPolygon,
} from 'geojson'

import carte2026Json from '@/assets/circonscriptions_electorales_2026_simplifie.json'
import carte2022Json from '@/assets/circonscriptions_electorales_2022_simplifie.json'

interface CirconscriptionProperties {
  CO_CEP: number
  NM_CEP: string
  NMTRI_CEP: string
}

type CarteElectorale = FeatureCollection<
    Polygon | MultiPolygon,
    CirconscriptionProperties
>

// const router = useRouter()

const mapElement = ref<HTMLElement | null>(null)

const anneeCarte = ref<2022 | 2026>(2026)

const carte2026 = carte2026Json as CarteElectorale

const carte2022 = carte2022Json as CarteElectorale

let map: L.Map | null = null

let geoJsonLayer: L.GeoJSON | null = null
let masqueLayer: L.GeoJSON | null = null
let selectedLayer: L.Path | null = null

let initialBounds: L.LatLngBounds | null = null

function zoomTo(
    lat: number,
    lng: number,
    zoom: number,
) {
  map?.flyTo(
      [lat, lng],
      zoom,
      {
        duration: 1,
      },
  )
}

function resetZoom() {
  if (!map || !initialBounds) {
    return
  }

  map.flyTo(
      initialBounds.getCenter(),
      map.getBoundsZoom(initialBounds),
      {
        duration: 1,
      },
  )
}

const masques = new Map<2022 | 2026, GeoJSON.Feature>()

function creerMasque(
    data: CarteElectorale,
    annee: 2022 | 2026,
) {
  if (!map) {
    return
  }

  if (masqueLayer) {
    map.removeLayer(masqueLayer)
    masqueLayer = null
  }

  let masque = masques.get(annee)

  if (!masque) {
    const quebec = union(
        featureCollection(data.features),
    )

    if (!quebec) {
      return
    }

    const monde = polygon([
      [
        [-180, -85],
        [180, -85],
        [180, 85],
        [-180, 85],
        [-180, -85],
      ],
    ])

    masque = difference(
        featureCollection([
          monde,
          quebec,
        ]),
    ) ?? undefined

    if (!masque) {
      return
    }

    masques.set(annee, masque)
  }

  masqueLayer = L.geoJSON(
      masque,
      {
        pane: 'masque',
        style: {
          fillColor: '#111111',
          fillOpacity: 0.75,
          stroke: false,
        },
        interactive: false,
      },
  )

  masqueLayer.addTo(map)
}

function afficherCarte(
    data: CarteElectorale,
    annee: 2022 | 2026,
) {
  if (!map) {
    return
  }

  anneeCarte.value = annee

  /*
   * Supprime la carte électorale courante.
   */
  if (geoJsonLayer) {
    map.removeLayer(geoJsonLayer)
    geoJsonLayer = null
  }

  /*
   * Recrée le masque selon la géométrie
   * de la carte sélectionnée.
   */
  creerMasque(data, annee)

  /*
   * Crée la nouvelle couche électorale.
   */
  geoJsonLayer = L.geoJSON(
      data,
      {
        style: () => ({
          color: '#333',
          weight: 1,
          fillColor: '#3388ff',
          fillOpacity: 0.25,
        }),

        onEachFeature(feature, layer) {
          const properties =
              feature.properties as CirconscriptionProperties

          layer.bindTooltip(
              properties.NM_CEP,
          )

          layer.on({
            mouseover(event) {
              const target = event.target as L.Path

              target.setStyle({
                weight: 2,
                fillOpacity: 0.5,
              })
            },

            mouseout(event) {
              const target = event.target as L.Path

              if (target !== selectedLayer) {
                geoJsonLayer?.resetStyle(target)
              }
            },

            click(event) {
              const target = event.target as L.Path

              // Réinitialise l'ancienne sélection
              if (selectedLayer && selectedLayer !== target) {
                geoJsonLayer?.resetStyle(selectedLayer)
              }

              selectedLayer = target

              // Même style que le survol
              selectedLayer.setStyle({
                weight: 2,
                fillOpacity: 0.5,
              })

              // @todo Aller vers la circonscription
            },
          })
        },
      },
  )

  geoJsonLayer.addTo(map)
}

function afficherCarte2022() {
  afficherCarte(
      carte2022,
      2022,
  )
}

function afficherCarte2026() {
  afficherCarte(
      carte2026,
      2026,
  )
}

onMounted(() => {
  if (!mapElement.value) {
    return
  }

  const quebecBounds = L.latLngBounds(
      [44.9, -79.8],
      [62.6, -57.0],
  )

  map = L.map(
      mapElement.value,
      {
        maxBounds: quebecBounds,
        maxBoundsViscosity: 1.0,
        minZoom: 5,
      },
  )

  /*
   * Pane situé entre les tuiles OpenStreetMap
   * et les circonscriptions.
   */
  map.createPane('masque')

  const masquePane =
      map.getPane('masque')

  if (masquePane) {
    masquePane.style.zIndex = '350'
    masquePane.style.pointerEvents = 'none'
  }

  /*
   * Fond OpenStreetMap.
   */
  L.tileLayer(
      'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
      {
        attribution:
            '&copy; OpenStreetMap contributors',

        maxZoom: 19,
        className: 'dark-map-tiles',
      },
  ).addTo(map)

  /*
   * Carte affichée par défaut.
   */
  afficherCarte2026()

  /*
   * Sauvegarde le cadrage initial.
   */
  if (geoJsonLayer) {
    initialBounds =
        geoJsonLayer.getBounds()

    map.fitBounds(
        initialBounds,
    )
  }
})

onUnmounted(() => {
  map?.remove()

  map = null
  geoJsonLayer = null
  masqueLayer = null
  initialBounds = null
})
</script>

<template>
  <main class="map-page">

    <!-- Sélection de l'année -->
    <div class="year-links">
      <button
          :class="{ active: anneeCarte === 2022 }"
          @click="afficherCarte2022"
      >
        2022
      </button>

      <button
          :class="{ active: anneeCarte === 2026 }"
          @click="afficherCarte2026"
      >
        2026
      </button>
    </div>

    <!-- Raccourcis géographiques -->
    <div class="city-links">

      <button @click="resetZoom">
        Québec au complet
      </button>

      <button
          @click="zoomTo(
          45.5019,
          -73.5674,
          10,
        )"
      >
        Montréal
      </button>

      <button
          @click="zoomTo(
          46.8139,
          -71.2080,
          10,
        )"
      >
        Québec
      </button>

      <button
          @click="zoomTo(
          46.3430,
          -72.5430,
          11,
        )"
      >
        Trois-Rivières
      </button>

      <button
          @click="zoomTo(
          45.4042,
          -71.8929,
          11,
        )"
      >
        Sherbrooke
      </button>

      <button
          @click="zoomTo(
          47.38,
          -61.86,
          9,
        )"
      >
        Îles-de-la-Madeleine
      </button>

    </div>

    <div
        ref="mapElement"
        class="map"
    />

  </main>
</template>

<style scoped>

.map-page {
  position: relative;
  width: 100%;
  height: 100vh;
}

.map {
  width: 100%;
  height: 100%;
}

/*
 * Choix de la carte électorale.
 */
.year-links {
  position: absolute;
  top: 20px;
  right: 20px;
  z-index: 1000;

  display: flex;
  gap: 8px;
}

.year-links button {
  padding: 8px 16px;

  border: 1px solid #888;
  border-radius: 4px;

  background: #333333;

  cursor: pointer;
}

.year-links button:hover {
  background: #303030;
}

.year-links button.active {
  font-weight: bold;

  border: 2px solid;

  background: #222222;
}

/*
 * Raccourcis géographiques.
 */
.city-links {
  position: absolute;
  bottom: 20px;
  left: 20px;
  z-index: 1000;

  display: flex;
  flex-direction: column;
  gap: 8px;
}

.city-links button {
  min-width: 140px;

  padding: 8px 12px;

  border: 1px solid #888;
  border-radius: 4px;

  background: #333333;

  cursor: pointer;
}

.city-links button:hover {
  background: #222222;
}
</style>

