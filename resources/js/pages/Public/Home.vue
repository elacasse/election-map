<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import L from 'leaflet'
import 'leaflet/dist/leaflet.css'
import '../../../css/map.css'

import { union } from '@turf/union'
import { difference } from '@turf/difference'
import { polygon, featureCollection } from '@turf/helpers'

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

interface PartyStanding {
  name: string
  abbreviation: string
  color: string | null
  won_district_count: number
  leading_district_count: number
}

interface DistrictStanding {
  source_district_number: number
  party_color: string | null
  results_final: boolean
}

interface ElectionSummary {
  election: {
    year: number
    captured_at: string | null
    results_final: boolean
  }
  parties: PartyStanding[]
  districts: DistrictStanding[]
}

const partyStandingsLabel = computed(() =>
    modeCarte.value !== 2026
        ? 'Sièges'
        : 'Gagnées / En avance'
)

const partyStandings = ref<PartyStanding[]>([])
const resultsLoading = ref(false)
const resultsError = ref<string | null>(null)

const districtStandings =
    ref<Map<number, DistrictStanding>>(new Map())

let resultsAbortController: AbortController | null = null

const mapElement = ref<HTMLElement | null>(null)

type MapMode = 2022 | 'dissolution' | 2026
const modeCarte = ref<MapMode>(2026)

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

function districtColor(
    districtNumber: number,
): string {
  const district =
      districtStandings.value.get(districtNumber)

  return partyColor(
      district?.party_color ?? null,
  )
}

function partyColor(color: string | null): string {
  if (!color) {
    return '#808080'
  }

  return color.startsWith('#')
      ? color
      : `#${color}`
}

function appliquerCouleursCirconscriptions(): void {
  geoJsonLayer?.setStyle(feature => {
    const properties =
        feature?.properties as
            CirconscriptionProperties | undefined

    if (!properties) {
      return {}
    }

    return {
      color: '#333333',
      weight: 1,
      fillColor: districtColor(
          Number(properties.CO_CEP),
      ),
      fillOpacity: 0.6,
    }
  })
}

interface AssemblyDissolutionSummary {
  parties: {
    name: string
    abbreviation: string
    color: string | null
    won_district_count: number
    leading_district_count: number
  }[]

  districts: DistrictStanding[]
}

async function chargerCompositionDissolution(): Promise<void> {
  resultsAbortController?.abort()

  const controller = new AbortController()

  resultsAbortController = controller
  resultsLoading.value = true
  resultsError.value = null

  try {
    const response = await fetch(
        '/api/assembly/dissolution',
        {
          headers: {
            Accept: 'application/json',
          },
          signal: controller.signal,
        },
    )

    if (!response.ok) {
      throw new Error(
          `Unable to load assembly composition: ${response.status}`,
      )
    }

    const data =
        await response.json() as AssemblyDissolutionSummary

    partyStandings.value = data.parties.map(
        party => ({
          name: party.name,
          abbreviation: party.abbreviation,
          color: party.color,
          won_district_count: party.won_district_count,
          leading_district_count: party.leading_district_count,
        }),
    )

    districtStandings.value = new Map(
        data.districts.map(
            district => [
              district.source_district_number,
              district,
            ],
        ),
    )

    appliquerCouleursCirconscriptions()
  } catch (error) {
    if (
        error instanceof DOMException &&
        error.name === 'AbortError'
    ) {
      return
    }

    partyStandings.value = []
    districtStandings.value = new Map()

    resultsError.value =
        'Impossible de charger la composition de l’Assemblée.'
  } finally {
    if (resultsAbortController === controller) {
      resultsLoading.value = false
    }
  }
}

async function chargerResultats(
    annee: 2022 | 2026,
): Promise<void> {
  resultsAbortController?.abort()

  const controller = new AbortController()

  resultsAbortController = controller
  resultsLoading.value = true
  resultsError.value = null

  try {
    const response = await fetch(
        `/api/elections/${annee}/results/summary`,
        {
          headers: {
            Accept: 'application/json',
          },
          signal: controller.signal,
        },
    )

    if (!response.ok) {
      throw new Error(
          `Unable to load election results: ${response.status}`,
      )
    }

    const data = await response.json() as ElectionSummary

    partyStandings.value = data.parties

    districtStandings.value = new Map(
        data.districts.map(
            district => [
              district.source_district_number,
              district,
            ],
        ),
    )

    appliquerCouleursCirconscriptions()
  } catch (error) {
    if (
        error instanceof DOMException &&
        error.name === 'AbortError'
    ) {
      return
    }

    partyStandings.value = []
    resultsError.value =
        'Impossible de charger les résultats.'
  } finally {
    if (resultsAbortController === controller) {
      resultsLoading.value = false
    }
  }
}

function afficherCarte(
    data: CarteElectorale,
    mode: MapMode,
) {
  modeCarte.value = mode

  if (!map) {
    return
  }

  let annee: 2022 | 2026

  if (mode === 'dissolution') {
    void chargerCompositionDissolution()
    annee = 2022
  } else {
    void chargerResultats(mode)
    annee = mode;
  }

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
        style: feature => {
          const properties =
              feature?.properties as
                  CirconscriptionProperties | undefined

          return {
            color: '#333333',
            weight: 1,
            fillColor: properties
                ? districtColor(Number(properties.CO_CEP))
                : '#808080',
            fillOpacity: 0.6,
          }
        },

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
                fillOpacity: 0.85,
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
                fillOpacity: 0.85,
              })

              // @todo Aller vers la circonscription
            },
          })
        },
      },
  )

  geoJsonLayer.addTo(map)
}

function afficherDissolution() {
  afficherCarte(
      carte2022,
      'dissolution',
  )
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
  resultsAbortController?.abort()
  resultsAbortController = null

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
          :class="{ active: modeCarte === 2022 }"
          @click="afficherCarte2022"
      >
        2022
      </button>

      <button
          :class="{ active: modeCarte === 'dissolution' }"
          @click="afficherDissolution"
      >
        Dissolution
      </button>

      <button
          :class="{ active: modeCarte === 2026 }"
          @click="afficherCarte2026"
      >
        2026
      </button>
    </div>

    <!-- Résultats par parti -->
    <section
        class="party-standings"
        aria-label="Résultats des partis"
    >
      <div class="party-standings-header">
        <span>Parti</span>

        <span>
          {{ partyStandingsLabel }}
        </span>
      </div>

      <div
          v-if="resultsLoading"
          class="party-standings-message"
      >
        Chargement...
      </div>

      <div
          v-else-if="resultsError"
          class="party-standings-message"
      >
        {{ resultsError }}
      </div>

      <template v-else>
        <div
            v-for="party in partyStandings"
            :key="party.abbreviation"
            class="party-standing"
            :style="{
          backgroundColor: partyColor(party.color),
        }"
        >
          <div class="party-name">
            {{ party.name }}
          </div>

          <div class="party-seats">
            <template v-if="modeCarte !== 2026">
              <strong>
                {{ party.won_district_count }}
              </strong>
            </template>

            <template v-else>
              <strong>
                {{ party.won_district_count }}
              </strong>

              <span>/</span>

              <strong>
                {{ party.leading_district_count }}
              </strong>
            </template>
          </div>
        </div>
      </template>
    </section>

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

/*
 * Résultats des partis.
 */
.party-standings {
  position: absolute;
  top: 72px;
  right: 20px;
  z-index: 1000;

  width: 360px;

  overflow: hidden;

  border: 1px solid #666666;
  border-radius: 6px;

  background: #222222;
}

.party-standings-header {
  display: flex;
  align-items: center;
  justify-content: space-between;

  padding: 8px 12px;

  font-size: 0.75rem;
  font-weight: bold;

  background: #222222;
}

.party-standings-message {
  padding: 16px 12px;

  text-align: center;

  background: #333333;
}

.party-standing {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;

  min-height: 52px;
  padding: 8px 12px;

  color: #ffffff;
  text-shadow: 0 1px 2px rgb(0 0 0 / 70%);
}

.party-name {
  min-width: 0;

  overflow: hidden;

  font-weight: 600;

  text-overflow: ellipsis;
  white-space: nowrap;
}

.party-seats {
  display: flex;
  flex-shrink: 0;
  align-items: baseline;
  gap: 5px;

  font-size: 1.2rem;
  font-variant-numeric: tabular-nums;
}

.party-seats span {
  opacity: 0.75;
}

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

