<script setup>
import { ref, reactive, computed, onMounted, onUnmounted } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import AgentSprite from '@/Components/AgentSprite.vue';
import {
    WAYPOINT_NODES,
    WAYPOINT_EDGES,
    WORKSTATION_CONFIG,
    DEFAULT_TYPING_CALIBRATION,
    createNullTypingCalibration,
} from '@/config/officeWaypoints';
import {
    preloadAgentSprites,
    TYPING_FRAME_POSITIONS,
    OFFICE_BG_URL,
    OFFICE_CHAIRS_FG_URL,
} from '@/config/agentSprites';

const props = defineProps({
    initialState: {
        type: Object,
        required: true,
    },
});

const SKIN_CATALOG = [
    { id: 1, name: 'Mint Bot', role: 'Auditor Statistik' },
    { id: 2, name: 'Kucing Kacamata', role: 'Pemeriksa Tabel' },
    { id: 3, name: 'Gadis Coral', role: 'Analis Narasi' },
    { id: 4, name: 'Beruang Mint', role: 'Validator Angka' },
    { id: 5, name: 'Robot Dasi Peach', role: 'Inspektur Layout' },
    { id: 6, name: 'Visor Bot Roda', role: 'Pemindai Cepat' },
    { id: 7, name: 'Hover Bot Spektral', role: 'Penguji Konsistensi' },
    { id: 8, name: 'Audio Bot Krem', role: 'Sinkronisasi Data' },
];

// Calibrated 5 Chair Seat Coordinates on the trimmed 3:2 office-bg.png
// Workstations 1, 3, 4, 5 have monitors at top-left ('left'); Workstation 2 has monitor at top-right ('right')
const DEFAULT_DESK_POSITIONS = {
    1: {
        ...WORKSTATION_CONFIG[1].seatPosition,
        label: WORKSTATION_CONFIG[1].label,
        deskClipInset: WORKSTATION_CONFIG[1].deskClipInset,
        chairClipInset: WORKSTATION_CONFIG[1].chairClipInset,
    },
    2: {
        ...WORKSTATION_CONFIG[2].seatPosition,
        label: WORKSTATION_CONFIG[2].label,
        deskClipInset: WORKSTATION_CONFIG[2].deskClipInset,
        chairClipInset: WORKSTATION_CONFIG[2].chairClipInset,
    },
    3: {
        ...WORKSTATION_CONFIG[3].seatPosition,
        label: WORKSTATION_CONFIG[3].label,
        deskClipInset: WORKSTATION_CONFIG[3].deskClipInset,
        chairClipInset: WORKSTATION_CONFIG[3].chairClipInset,
    },
    4: {
        ...WORKSTATION_CONFIG[4].seatPosition,
        label: WORKSTATION_CONFIG[4].label,
        deskClipInset: WORKSTATION_CONFIG[4].deskClipInset,
        chairClipInset: WORKSTATION_CONFIG[4].chairClipInset,
    },
    5: {
        ...WORKSTATION_CONFIG[5].seatPosition,
        label: WORKSTATION_CONFIG[5].label,
        deskClipInset: WORKSTATION_CONFIG[5].deskClipInset,
        chairClipInset: WORKSTATION_CONFIG[5].chairClipInset,
    },
};

// Typing & Keyboard Alignment Calibration State (initialized from null template then hydrated)
const typingCalibration = reactive(createNullTypingCalibration());
for (let s = 1; s <= 5; s++) {
    Object.assign(typingCalibration[s], JSON.parse(JSON.stringify(DEFAULT_TYPING_CALIBRATION[s])));
}
const calibrationTab = ref('typing'); // 'typing' | 'desk'
const activeMobileTab = ref('office'); // 'office' | 'agents' | 'logs' (for mobile viewport navigation)

const SERVER_POSITION = { top: 49.5, left: 48.4 };
const SERVER_LANDMARK = { top: 40.5, left: 48.4 };
const COFFEE_POSITION = { top: 38.5, left: 73.5 };
const COFFEE_LANDMARK = { top: 24.5, left: 75.5 };

const officeState = ref(
    props.initialState || {
        agents: [],
        recent_activities: [],
        summary: {
            has_active_audit: false,
            active_publication_title: null,
            active_publication_status: null,
            active_pub_total_pages: 0,
            active_pub_completed_pages: 0,
            total_agents: 5,
            active_agents: 0,
            ready_agents: 5,
            active_audits: 0,
            total_pages_processed: 0,
        },
        server_time: '09:05:12',
    }
);

const deskPositions = reactive(JSON.parse(JSON.stringify(DEFAULT_DESK_POSITIONS)));
const slotSkins = reactive({
    1: 1,
    2: 2,
    3: 3,
    4: 4,
    5: 5,
});

// IMPORTANT: Demo Mode is OFF by default so the page 100% reflects real backend audit state!
const demoMode = ref(false);
// Manual preview overrides (when user clicks a pose button in the bottom inspector)
const manualPoseOverride = reactive({
    1: null,
    2: null,
    3: null,
    4: null,
    5: null,
});

// Demo choreography state (ONLY used when user explicitly enables `demoMode`)
const slotChoreography = reactive({
    1: { state: 'working', statusText: '[Demo] Hal 23: Menganalisis tabel...', demoPage: 23, progress: 72, pubTitle: '[Simulasi] Kecamatan Ampana Kota Dalam Angka' },
    2: { state: 'working', statusText: '[Demo] Hal 41: Memeriksa jumlah...', demoPage: 41, progress: 64, pubTitle: '[Simulasi] Kecamatan Batudaka Dalam Angka' },
    3: { state: 'working', statusText: '[Demo] Hal 18: Cek konsistensi...', demoPage: 18, progress: 85, pubTitle: '[Simulasi] Kabupaten Tojo Una-Una Dalam Angka' },
    4: { state: 'coffee', statusText: '[Demo] Rehat kopi & sinkronisasi...', demoPage: 54, progress: 48, pubTitle: '[Simulasi] Kecamatan Ratolindo Dalam Angka' },
    5: { state: 'done', statusText: '[Demo] Selesai cek 60 halaman!', demoPage: 60, progress: 100, pubTitle: '[Simulasi] Kecamatan Ulubongka Dalam Angka' },
});

const selectedSlot = ref(1);
const activeFeedFilter = ref('all'); // 'all' | 'live' | 'issue'
const liveSyncEnabled = ref(true);
const calibrationMode = ref(false);
const debugMode = ref(false);
const showSkinModal = ref(false);
const skinModalTargetSlot = ref(1);
const pingingAgentId = ref(null);
const pingingAll = ref(false);
const toastMessage = ref('');

// Track live path & animation telemetry per slot for debug visualization
const agentPathSnapshots = reactive({
    1: { currentTop: 33.4, currentLeft: 39.2, waypoints: [], visualState: 'idle', direction: 'left', walkFrame: 1 },
    2: { currentTop: 31.0, currentLeft: 58.2, waypoints: [], visualState: 'idle', direction: 'right', walkFrame: 1 },
    3: { currentTop: 56.4, currentLeft: 29.0, waypoints: [], visualState: 'idle', direction: 'left', walkFrame: 1 },
    4: { currentTop: 71.4, currentLeft: 51.0, waypoints: [], visualState: 'idle', direction: 'left', walkFrame: 1 },
    5: { currentTop: 57.2, currentLeft: 74.0, waypoints: [], visualState: 'idle', direction: 'left', walkFrame: 1 },
});

function handlePathUpdate(payload) {
    if (!payload?.slot) return;
    agentPathSnapshots[payload.slot] = {
        currentTop: payload.currentTop,
        currentLeft: payload.currentLeft,
        waypoints: payload.waypoints || [],
        visualState: payload.visualState || 'idle',
        direction: payload.direction || 'right',
        walkFrame: payload.walkFrame || 1,
    };
}

const waypointNodeList = computed(() => Object.values(WAYPOINT_NODES));
const waypointEdgeLines = computed(() => {
    return WAYPOINT_EDGES.map(([a, b], idx) => {
        const na = WAYPOINT_NODES[a];
        const nb = WAYPOINT_NODES[b];
        if (!na || !nb) return null;
        return { id: `edge-${idx}`, x1: na.left, y1: na.top, x2: nb.left, y2: nb.top };
    }).filter(Boolean);
});

let pollTimer = null;
let choreographyTimer = null;
let toastTimer = null;

function showToast(msg) {
    toastMessage.value = msg;
    if (toastTimer) clearTimeout(toastTimer);
    toastTimer = setTimeout(() => {
        toastMessage.value = '';
    }, 3500);
}

function loadLocalPreferences() {
    try {
        const savedSkins = localStorage.getItem('bps_agent_office_skins_v2');
        if (savedSkins) {
            const parsed = JSON.parse(savedSkins);
            for (let s = 1; s <= 5; s++) {
                if (parsed[s] >= 1 && parsed[s] <= 8) {
                    slotSkins[s] = parsed[s];
                }
            }
        }
        const savedDesks = localStorage.getItem('bps_agent_office_desks_v8');
        if (savedDesks) {
            const parsed = JSON.parse(savedDesks);
            for (let s = 1; s <= 5; s++) {
                if (parsed[s] && typeof parsed[s].top === 'number' && typeof parsed[s].left === 'number') {
                    deskPositions[s].top = parsed[s].top;
                    deskPositions[s].left = parsed[s].left;
                    if (parsed[s].facing) deskPositions[s].facing = parsed[s].facing;
                }
            }
        }
        const savedTyping = localStorage.getItem('bps_agent_office_typing_calib_v4');
        if (savedTyping) {
            const parsed = JSON.parse(savedTyping);
            for (let s = 1; s <= 5; s++) {
                if (parsed[s] && typeof parsed[s] === 'object') {
                    Object.assign(typingCalibration[s], parsed[s]);
                }
            }
        }
    } catch (e) {}
}

function saveSkinsToStorage() {
    try {
        localStorage.setItem('bps_agent_office_skins_v2', JSON.stringify(slotSkins));
    } catch (e) {}
}

function saveDesksToStorage() {
    try {
        localStorage.setItem('bps_agent_office_desks_v8', JSON.stringify(deskPositions));
    } catch (e) {}
}

function saveTypingCalibrationToStorage() {
    try {
        localStorage.setItem('bps_agent_office_typing_calib_v4', JSON.stringify(typingCalibration));
    } catch (e) {}
}

function resetDeskPositions() {
    for (let s = 1; s <= 5; s++) {
        deskPositions[s].top = DEFAULT_DESK_POSITIONS[s].top;
        deskPositions[s].left = DEFAULT_DESK_POSITIONS[s].left;
        deskPositions[s].facing = DEFAULT_DESK_POSITIONS[s].facing;
        Object.assign(typingCalibration[s], JSON.parse(JSON.stringify(DEFAULT_TYPING_CALIBRATION[s])));
    }
    saveDesksToStorage();
    saveTypingCalibrationToStorage();
    showToast('Kalibrasi kursi, keyboard, & typing 5 agen dikembalikan ke posisi standar.');
}

function handleUpdatePosition({ slot, top, left }) {
    if (!deskPositions[slot]) return;
    deskPositions[slot].top = top;
    deskPositions[slot].left = left;
    if (typingCalibration[slot]) {
        typingCalibration[slot].seatX = left;
        typingCalibration[slot].seatY = top;
    }
    saveDesksToStorage();
    saveTypingCalibrationToStorage();
}

function handleTypingCalibrationChange(slot) {
    if (!typingCalibration[slot]) return;
    if (typeof typingCalibration[slot].seatX === 'number' && typeof typingCalibration[slot].seatY === 'number') {
        deskPositions[slot].left = Number(typingCalibration[slot].seatX);
        deskPositions[slot].top = Number(typingCalibration[slot].seatY);
    }
    saveDesksToStorage();
    saveTypingCalibrationToStorage();
}

function toggleDeskFacing(slot) {
    if (!deskPositions[slot]) return;
    deskPositions[slot].facing = deskPositions[slot].facing === 'left' ? 'right' : 'left';
    saveDesksToStorage();
}

const hasActiveAudit = computed(() => Boolean(officeState.value?.summary?.has_active_audit));
const activePubTotalPages = computed(() => Number(officeState.value?.summary?.active_pub_total_pages || 0));
const activePubCompletedPages = computed(() => Number(officeState.value?.summary?.active_pub_completed_pages || 0));

const normalizedAgents = computed(() => {
    const rawAgents = officeState.value?.agents || [];
    const list = [];

    for (let slot = 1; slot <= 5; slot++) {
        const found = rawAgents.find((a) => Number(a.slot) === slot) || {
            id: slot,
            slot,
            name: `Agent ${slot}`,
            model: 'gemini-2.5-flash',
            enabled: true,
            db_status: 'ready',
            office_status: 'idle',
            test_message: 'Visi AI siap memeriksa dokumen',
            vision_tested_at: 'Belum diuji',
            assigned_pages: 0,
            completed_pages: 0,
            active_audit_completed_pages: 0,
            total_calls: 0,
            avg_duration_ms: 0,
            active_task: null,
            last_activity: null,
            active_publication_title: null,
        };

        let behaviorState = 'idle';
        let statusText = 'Siaga menunggu pemeriksaan';
        let activePubTitle = 'Tidak ada pemeriksaan aktif';
        let progressPct = 0;
        let progressLabel = 'Menunggu tugas';
        let badgeLabel = 'Siaga';
        let badgeStyle = 'bg-[#FCF9EA] text-[#5A5150] border-[#D7D1C8]';

        // 1. Check if disabled/offline
        if (!found.enabled || found.office_status === 'offline') {
            behaviorState = 'offline';
            statusText = 'Agen dinonaktifkan';
            activePubTitle = 'Slot agen nonaktif';
            badgeLabel = 'Nonaktif';
            badgeStyle = 'bg-[#FCF9EA] text-[#5A5150] border-[#D7D1C8]';
        }
        // 2. Check if user explicitly turned on Demo Mode
        else if (demoMode.value) {
            const choreo = slotChoreography[slot];
            behaviorState = choreo.state;
            statusText = choreo.statusText;
            activePubTitle = choreo.pubTitle;
            progressPct = choreo.progress;
            progressLabel = `${choreo.progress}% (Simulasi)`;
            if (behaviorState === 'working') {
                badgeLabel = 'Simulasi Aktif';
                badgeStyle = 'bg-[#BADFDB] text-[#245F59] border-[#96C9C3]';
            } else if (behaviorState === 'coffee' || behaviorState === 'walking_to_coffee') {
                badgeLabel = 'Simulasi Kopi';
                badgeStyle = 'bg-[#FCF9EA] text-[#85500F] border-[#D7D1C8]';
            } else if (behaviorState === 'walking_to_server' || behaviorState === 'at_server') {
                badgeLabel = 'Simulasi Server';
                badgeStyle = 'bg-[#BADFDB] text-[#245F59] border-[#96C9C3]';
            } else {
                badgeLabel = 'Simulasi Done';
                badgeStyle = 'bg-[#FFBDBD] text-[#302B2A] border-[#E88D8D]';
            }
        }
        // 3. Check if user triggered a temporary manual pose preview on this slot
        else if (manualPoseOverride[slot]) {
            const ov = manualPoseOverride[slot];
            behaviorState = ov.state;
            statusText = ov.statusText;
            activePubTitle = 'Pratinjau gerakan karakter';
            badgeLabel = 'Pratinjau';
            badgeStyle = 'bg-[#FFBDBD] text-[#302B2A] border-[#E88D8D]';
        }
        // 4. REAL BACKEND STATE: Testing Vision / Ping
        else if (found.office_status === 'testing') {
            behaviorState = 'walking_to_server';
            activePubTitle = 'Pengujian Koneksi & Visi AI';
            statusText = 'Menguji respons model AI...';
            progressPct = 50;
            progressLabel = 'Menguji...';
            badgeLabel = 'Uji Visi';
            badgeStyle = 'bg-[#BADFDB] text-[#245F59] border-[#96C9C3]';
        }
        // 5. REAL BACKEND STATE: Actively analyzing a publication page right now!
        else if (found.office_status === 'analyzing' && found.active_task) {
            behaviorState = 'analyzing';
            activePubTitle = found.active_task.publication_title;
            statusText = `Hal #${found.active_task.physical_page}: Menganalisis halaman...`;
            progressPct =
                activePubTotalPages.value > 0
                    ? Math.min(100, Math.max(5, Math.round((activePubCompletedPages.value / activePubTotalPages.value) * 100)))
                    : 50;
            progressLabel = `${found.active_audit_completed_pages || 0} hal selesai`;
            badgeLabel = 'Aktif Bekerja';
            badgeStyle = 'bg-[#BADFDB] text-[#245F59] border-[#96C9C3]';
        }
        // 6. REAL BACKEND STATE: Just finished a page (< 6s ago) during an active audit -> submitting to central server!
        else if (found.office_status === 'submitting' && found.last_activity) {
            behaviorState = 'walking_to_server';
            activePubTitle = found.last_activity.publication_title || found.active_publication_title || 'Publikasi Aktif';
            statusText = `Setor hasil Hal #${found.last_activity.physical_page || 1} ke Server...`;
            progressPct =
                activePubTotalPages.value > 0
                    ? Math.min(100, Math.round((activePubCompletedPages.value / activePubTotalPages.value) * 100))
                    : 100;
            progressLabel = `${found.active_audit_completed_pages || 0} hal selesai`;
            badgeLabel = 'Setor Hasil';
            badgeStyle = 'bg-[#BADFDB] text-[#245F59] border-[#96C9C3]';
        }
        // 7. REAL BACKEND STATE: Rate limit / capacity cooldown during an active audit -> at Coffee Dispenser!
        else if (found.office_status === 'cooldown') {
            behaviorState = 'coffee';
            activePubTitle = found.active_publication_title || 'Publikasi Aktif';
            statusText = 'Cooldown API · Menunggu giliran...';
            progressPct =
                activePubTotalPages.value > 0
                    ? Math.min(100, Math.round((activePubCompletedPages.value / activePubTotalPages.value) * 100))
                    : 30;
            progressLabel = 'Cooldown API';
            badgeLabel = 'Cooldown';
            badgeStyle = 'bg-[#FCF9EA] text-[#85500F] border-[#D7D1C8]';
        }
        // 8. REAL BACKEND STATE: Active audit is running on a publication, agent is ready waiting for next page from queue
        else if (found.office_status === 'standby_queue') {
            behaviorState = 'idle';
            activePubTitle = found.active_publication_title || 'Pemeriksaan Berjalan';
            statusText =
                found.active_audit_completed_pages > 0
                    ? `Siap ambil antrean (Selesai ${found.active_audit_completed_pages} hal)`
                    : 'Menunggu pembagian halaman dari antrean...';
            progressPct =
                activePubTotalPages.value > 0
                    ? Math.min(100, Math.round((activePubCompletedPages.value / activePubTotalPages.value) * 100))
                    : 0;
            progressLabel = `${found.active_audit_completed_pages || 0} hal di dokumen ini`;
            badgeLabel = 'Antrean Siap';
            badgeStyle = 'bg-[#FFBDBD] text-[#302B2A] border-[#E88D8D]';
        }
        // 9. REAL BACKEND STATE: Error on agent
        else if (found.office_status === 'error') {
            behaviorState = 'idle';
            activePubTitle = 'Koneksi Agen Bermasalah';
            statusText = found.test_message || 'Periksa API key / model di Pengaturan';
            badgeLabel = 'Error';
            badgeStyle = 'bg-[#FCF9EA] text-[#A5363F] border-[#FFA4A4]';
        }
        // 10. REAL BACKEND STATE: IDLE / STANDBY (No active audit is running right now!)
        else {
            behaviorState = 'idle';
            activePubTitle = 'Tidak ada pemeriksaan aktif';
            if (found.last_activity && found.last_activity.physical_page) {
                statusText = `Siaga · Terakhir Hal #${found.last_activity.physical_page} (${found.last_activity.time_human})`;
            } else {
                statusText = 'Siaga di meja · Menunggu pemeriksaan dimulai';
            }
            progressPct = 0;
            progressLabel = 'Idle (0 tugas aktif)';
            badgeLabel = 'Siaga (Idle)';
            badgeStyle = 'bg-[#FCF9EA] text-[#5A5150] border-[#D7D1C8]';
        }

        const skinMeta = SKIN_CATALOG.find((s) => s.id === slotSkins[slot]) || SKIN_CATALOG[0];

        list.push({
            ...found,
            slot,
            skinId: slotSkins[slot] || slot,
            skinMeta,
            deskPosition: deskPositions[slot],
            behaviorState,
            statusText,
            activePubTitle,
            progressPct,
            progressLabel,
            badgeLabel,
            badgeStyle,
        });
    }

    return list;
});

const selectedAgent = computed(() => {
    return normalizedAgents.value.find((a) => a.slot === selectedSlot.value) || normalizedAgents.value[0];
});

const readyAgentsCount = computed(() => {
    return Number(officeState.value?.summary?.ready_agents ?? normalizedAgents.value.filter((a) => a.enabled).length);
});

const activelyWorkingCount = computed(() => {
    if (demoMode.value) {
        return normalizedAgents.value.filter((a) => a.behaviorState === 'working' || a.behaviorState === 'analyzing').length;
    }
    return Number(officeState.value?.summary?.active_agents || 0);
});

const totalProcessedPagesDisplay = computed(() => {
    return Number(officeState.value?.summary?.total_pages_processed || 0);
});

// Live Feed Activities: ONLY real DB records by default!
const combinedActivities = computed(() => {
    const real = officeState.value?.recent_activities || [];
    const mappedReal = real.map((item) => ({
        ...item,
        agent_slot: item.agent_slot || 1,
        created_at_time: item.created_at_time || '',
        created_at_human: item.created_at_human || '',
        is_live: Boolean(item.is_live),
        detail:
            item.status === 'success' || item.status === 'readable' || item.status === 'verified_readable'
                ? `Halaman #${item.physical_page || 1} selesai diperiksa tanpa kendala API.`
                : `Respons pemeriksaan halaman #${item.physical_page || 1} (${item.status}).`,
    }));

    if (activeFeedFilter.value === 'all') {
        return mappedReal;
    }
    if (activeFeedFilter.value === 'live') {
        return mappedReal.filter((x) => x.is_live);
    }
    if (activeFeedFilter.value === 'issue') {
        return mappedReal.filter(
            (x) =>
                x.status === 'issue' ||
                x.status === 'error' ||
                x.status === 'failed' ||
                x.status === 'failed_validation' ||
                x.status === 'unreadable'
        );
    }
    return mappedReal;
});

const liveActivitiesCount = computed(() => {
    return (officeState.value?.recent_activities || []).filter((x) => x.is_live).length;
});

// Demo choreography loop ONLY runs when demoMode.value === true
let choreoStepCounter = 0;

function runChoreographyTick() {
    if (!demoMode.value || calibrationMode.value) return;

    choreoStepCounter++;
    const slotToMove = (choreoStepCounter % 5) + 1;
    const current = slotChoreography[slotToMove];
    if (!current) return;

    if (current.state === 'working') {
        if (choreoStepCounter % 2 === 0) {
            current.state = 'walking_to_server';
            current.statusText = `[Demo] Setor Hal ${current.demoPage} ke Server...`;
            setTimeout(() => {
                if (demoMode.value && current.state === 'walking_to_server') {
                    current.state = 'at_server';
                    current.statusText = `[Demo] Sinkronisasi Hal ${current.demoPage}...`;
                    current.demoPage = (current.demoPage % 68) + 1;
                    current.progress = Math.min(100, current.progress + 4);
                }
            }, 2600);
            setTimeout(() => {
                if (demoMode.value && current.state === 'at_server') {
                    current.state = 'working';
                    current.statusText = `[Demo] Hal ${current.demoPage}: Kembali ke meja...`;
                }
            }, 5400);
        } else {
            current.state = 'walking_to_coffee';
            current.statusText = '[Demo] Menuju dispenser kopi...';
            setTimeout(() => {
                if (demoMode.value && current.state === 'walking_to_coffee') {
                    current.state = 'coffee';
                    current.statusText = '[Demo] Rehat kopi (Cooldown API)...';
                }
            }, 3100);
            setTimeout(() => {
                if (demoMode.value && current.state === 'coffee') {
                    current.state = 'working';
                    current.demoPage = (current.demoPage % 68) + 1;
                    current.statusText = `[Demo] Hal ${current.demoPage}: Memeriksa narasi...`;
                }
            }, 6800);
        }
    } else {
        current.state = 'working';
        current.demoPage = (current.demoPage % 68) + 1;
        current.progress = current.progress >= 100 ? 18 : current.progress + 5;
        current.statusText = `[Demo] Hal ${current.demoPage}: Menganalisis halaman...`;
    }
}

function toggleDemoMode() {
    demoMode.value = !demoMode.value;
    if (demoMode.value) {
        showToast('Mode Simulasi Demo diaktifkan (hanya visualisasi contoh).');
    } else {
        for (let s = 1; s <= 5; s++) manualPoseOverride[s] = null;
        showToast('Kembali ke Mode Akurat Real-Time (data asli database).');
    }
}

// Temporary pose preview when user clicks a button in the bottom inspector
let overrideTimers = {};

function commandAgentBehavior(slot, targetBehavior) {
    if (overrideTimers[slot]) clearTimeout(overrideTimers[slot]);

    if (targetBehavior === 'reset') {
        manualPoseOverride[slot] = null;
        return;
    }

    if (targetBehavior === 'working') {
        manualPoseOverride[slot] = {
            state: 'working',
            statusText: 'Pratinjau: Kembali ke meja & mengetik...',
        };
    } else if (targetBehavior === 'server') {
        manualPoseOverride[slot] = {
            state: 'walking_to_server',
            statusText: 'Pratinjau: Berjalan lewat koridor ke Server...',
        };
        setTimeout(() => {
            if (manualPoseOverride[slot]?.state === 'walking_to_server') {
                manualPoseOverride[slot] = {
                    state: 'at_server',
                    statusText: 'Pratinjau: Sinkronisasi di Server...',
                };
            }
        }, 2600);
    } else if (targetBehavior === 'coffee') {
        manualPoseOverride[slot] = {
            state: 'walking_to_coffee',
            statusText: 'Pratinjau: Berjalan lewat koridor ke Pojok Kopi...',
        };
        setTimeout(() => {
            if (manualPoseOverride[slot]?.state === 'walking_to_coffee') {
                manualPoseOverride[slot] = {
                    state: 'coffee',
                    statusText: 'Pratinjau: Minum kopi santai ☕',
                };
            }
        }, 3100);
    } else if (targetBehavior === 'done') {
        manualPoseOverride[slot] = {
            state: 'done',
            statusText: 'Pratinjau: Melambaikan tangan 👋',
        };
    }

    // Automatically return to real backend state after 11 seconds (enough time to walk & interact)
    overrideTimers[slot] = setTimeout(() => {
        manualPoseOverride[slot] = null;
    }, 11000);
}

async function fetchOfficeState() {
    if (!liveSyncEnabled.value) return;
    try {
        const res = await fetch('/api/agent-office/state', {
            headers: { Accept: 'application/json' },
        });
        if (res.ok) {
            const data = await res.json();
            officeState.value = data;
        }
    } catch (e) {}
}

async function pingSingleAgent(agent) {
    if (!agent?.id || pingingAgentId.value) return;
    pingingAgentId.value = agent.id;
    commandAgentBehavior(agent.slot, 'server');
    try {
        const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        const res = await fetch(`/agent-office/${agent.id}/test`, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf,
            },
        });
        const data = await res.json().catch(() => ({}));
        showToast(data.message || `Ping visi dikirim ke ${agent.name} (Slot #${agent.slot}).`);
        await fetchOfficeState();
    } catch (e) {
        showToast(`Ping terkirim ke ${agent.name}.`);
    } finally {
        pingingAgentId.value = null;
    }
}

async function pingAllAgents() {
    if (pingingAll.value) return;
    pingingAll.value = true;
    try {
        const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        const res = await fetch('/agent-office/test-all', {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf,
            },
        });
        const data = await res.json().catch(() => ({}));
        showToast(data.message || 'Pengujian seluruh 5 agen aktif telah dikirim ke antrean.');
        await fetchOfficeState();
    } catch (e) {
        showToast('Perintah uji dikirim ke seluruh agen.');
    } finally {
        pingingAll.value = false;
    }
}

function openSkinPickerForSlot(slot) {
    skinModalTargetSlot.value = slot;
    selectedSlot.value = slot;
    showSkinModal.value = true;
}

function selectSkinForSlot(skinId) {
    slotSkins[skinModalTargetSlot.value] = skinId;
    saveSkinsToStorage();
    showToast(`Karakter Slot #${skinModalTargetSlot.value} diubah ke ${SKIN_CATALOG.find((s) => s.id === skinId)?.name}.`);
}

// ============================================================================
// INTERACTIVE OFFICE CAMERA: ZOOM IN / ZOOM OUT (CTRL+WHEEL & 2-FINGER PINCH)
// + DRAG PANNING WHEN ZOOMED IN (SCOPED EXCLUSIVELY TO OFFICE STAGE)
// ============================================================================
const MIN_OFFICE_ZOOM = 1.0;
const MAX_OFFICE_ZOOM = 3.0;

const officeViewportRef = ref(null);
const cameraZoom = ref(1.0);
const cameraPanX = ref(0);
const cameraPanY = ref(0);
const isPanning = ref(false);
const isTouchPinching = ref(false);

let panPointerStart = { x: 0, y: 0, originX: 0, originY: 0, active: false };
let touchPinchState = {
    active: false,
    startDist: 0,
    startZoom: 1,
    lastMidX: 0,
    lastMidY: 0,
    singleTouchActive: false,
    singleLastX: 0,
    singleLastY: 0,
};

function clampCameraPan(nextPanX, nextPanY, scale = cameraZoom.value) {
    const el = officeViewportRef.value;
    if (!el || scale <= 1.001) {
        return { x: 0, y: 0 };
    }
    const rect = el.getBoundingClientRect();
    const minX = rect.width * (1 - scale);
    const minY = rect.height * (1 - scale);
    return {
        x: Math.min(0, Math.max(minX, nextPanX)),
        y: Math.min(0, Math.max(minY, nextPanY)),
    };
}

function zoomOfficeAtPoint(targetScale, clientX, clientY) {
    const el = officeViewportRef.value;
    const clampedScale = Math.min(
        MAX_OFFICE_ZOOM,
        Math.max(MIN_OFFICE_ZOOM, Number(targetScale.toFixed(3)))
    );

    if (!el || clampedScale <= 1.001) {
        cameraZoom.value = 1.0;
        cameraPanX.value = 0;
        cameraPanY.value = 0;
        return;
    }

    const rect = el.getBoundingClientRect();
    const focalX = typeof clientX === 'number' ? clientX - rect.left : rect.width / 2;
    const focalY = typeof clientY === 'number' ? clientY - rect.top : rect.height / 2;

    const oldScale = cameraZoom.value;
    const worldX = (focalX - cameraPanX.value) / oldScale;
    const worldY = (focalY - cameraPanY.value) / oldScale;

    const rawPanX = focalX - worldX * clampedScale;
    const rawPanY = focalY - worldY * clampedScale;
    const clampedPan = clampCameraPan(rawPanX, rawPanY, clampedScale);

    cameraZoom.value = clampedScale;
    cameraPanX.value = clampedPan.x;
    cameraPanY.value = clampedPan.y;
}

function zoomOfficeByStep(deltaStep) {
    const el = officeViewportRef.value;
    if (!el) return;
    const rect = el.getBoundingClientRect();
    zoomOfficeAtPoint(
        cameraZoom.value + deltaStep,
        rect.left + rect.width / 2,
        rect.top + rect.height / 2
    );
}

function resetOfficeCamera() {
    cameraZoom.value = 1.0;
    cameraPanX.value = 0;
    cameraPanY.value = 0;
}

function onOfficeDblClick(e) {
    if (e.target?.closest?.('.office-hud-controls')) return;
    if (cameraZoom.value > 1.05) {
        resetOfficeCamera();
    } else {
        zoomOfficeAtPoint(1.85, e.clientX, e.clientY);
    }
}

/**
 * Non-passive wheel handler on the Office Stage:
 * - Ctrl + Wheel (or 2-finger trackpad pinch which sets e.ctrlKey = true in Chrome/Edge/Firefox):
 *   Intercepts browser page zoom and zooms ONLY the Office Diorama around cursor!
 * - Wheel / 2-finger trackpad scroll while already zoomed in (`cameraZoom > 1.01`):
 *   Smoothly pans across the zoomed-in office floor.
 */
function onOfficeWheel(e) {
    if (e.ctrlKey || e.metaKey) {
        e.preventDefault();
        e.stopPropagation();
        const zoomFactor = Math.exp(-e.deltaY * 0.0045);
        zoomOfficeAtPoint(cameraZoom.value * zoomFactor, e.clientX, e.clientY);
        return;
    }

    if (cameraZoom.value > 1.01) {
        e.preventDefault();
        e.stopPropagation();
        const clamped = clampCameraPan(
            cameraPanX.value - e.deltaX * 0.9,
            cameraPanY.value - e.deltaY * 0.9,
            cameraZoom.value
        );
        cameraPanX.value = clamped.x;
        cameraPanY.value = clamped.y;
    }
}

/**
 * Mouse / Pointer Drag Panning when zoomed in (`cameraZoom > 1.01`)
 */
function onOfficePointerDown(e) {
    if (e.pointerType === 'touch') return; // Handled by dedicated multi-touch handlers
    if (e.button !== 0 && e.button !== 1) return;
    if (e.target?.closest?.('.office-hud-controls')) return;
    if (cameraZoom.value <= 1.01) return;

    panPointerStart = {
        x: e.clientX,
        y: e.clientY,
        originX: cameraPanX.value,
        originY: cameraPanY.value,
        active: true,
    };
    window.addEventListener('pointermove', onOfficePointerMove);
    window.addEventListener('pointerup', onOfficePointerUp);
}

function onOfficePointerMove(e) {
    if (!panPointerStart.active) return;
    const dx = e.clientX - panPointerStart.x;
    const dy = e.clientY - panPointerStart.y;
    if (!isPanning.value && Math.hypot(dx, dy) > 3) {
        isPanning.value = true;
    }
    if (isPanning.value) {
        const clamped = clampCameraPan(
            panPointerStart.originX + dx,
            panPointerStart.originY + dy,
            cameraZoom.value
        );
        cameraPanX.value = clamped.x;
        cameraPanY.value = clamped.y;
    }
}

function onOfficePointerUp() {
    panPointerStart.active = false;
    isPanning.value = false;
    window.removeEventListener('pointermove', onOfficePointerMove);
    window.removeEventListener('pointerup', onOfficePointerUp);
}

/**
 * Two-Finger Touch Pinch-to-Zoom & Touch Drag Panning on Touchscreen / Mobile / Tablet
 */
function getTouchPairMetrics(touches) {
    const t1 = touches[0];
    const t2 = touches[1];
    const dist = Math.hypot(t2.clientX - t1.clientX, t2.clientY - t1.clientY);
    const midX = (t1.clientX + t2.clientX) / 2;
    const midY = (t1.clientY + t2.clientY) / 2;
    return { dist: Math.max(10, dist), midX, midY };
}

function onOfficeTouchStart(e) {
    if (e.target?.closest?.('.office-hud-controls')) return;

    if (e.touches.length === 2) {
        e.preventDefault();
        const { dist, midX, midY } = getTouchPairMetrics(e.touches);
        touchPinchState.active = true;
        touchPinchState.singleTouchActive = false;
        touchPinchState.startDist = dist;
        touchPinchState.startZoom = cameraZoom.value;
        touchPinchState.lastMidX = midX;
        touchPinchState.lastMidY = midY;
        isTouchPinching.value = true;
    } else if (e.touches.length === 1 && cameraZoom.value > 1.01 && !calibrationMode.value) {
        touchPinchState.singleTouchActive = true;
        touchPinchState.singleLastX = e.touches[0].clientX;
        touchPinchState.singleLastY = e.touches[0].clientY;
    }
}

function onOfficeTouchMove(e) {
    if (e.touches.length === 2 && touchPinchState.active) {
        e.preventDefault();
        const { dist, midX, midY } = getTouchPairMetrics(e.touches);

        // Apply two-finger midpoint pan delta first
        const panDx = midX - touchPinchState.lastMidX;
        const panDy = midY - touchPinchState.lastMidY;
        cameraPanX.value += panDx;
        cameraPanY.value += panDy;
        touchPinchState.lastMidX = midX;
        touchPinchState.lastMidY = midY;

        // Apply pinch scale anchored at current two-finger midpoint
        const nextScale = touchPinchState.startZoom * (dist / touchPinchState.startDist);
        zoomOfficeAtPoint(nextScale, midX, midY);
    } else if (
        e.touches.length === 1 &&
        touchPinchState.singleTouchActive &&
        cameraZoom.value > 1.01 &&
        !calibrationMode.value
    ) {
        e.preventDefault();
        const t = e.touches[0];
        const dx = t.clientX - touchPinchState.singleLastX;
        const dy = t.clientY - touchPinchState.singleLastY;
        touchPinchState.singleLastX = t.clientX;
        touchPinchState.singleLastY = t.clientY;
        isPanning.value = true;
        const clamped = clampCameraPan(cameraPanX.value + dx, cameraPanY.value + dy, cameraZoom.value);
        cameraPanX.value = clamped.x;
        cameraPanY.value = clamped.y;
    }
}

function onOfficeTouchEnd(e) {
    if (e.touches.length < 2) {
        touchPinchState.active = false;
        isTouchPinching.value = false;
    }
    if (e.touches.length === 1 && cameraZoom.value > 1.01 && !calibrationMode.value) {
        touchPinchState.singleTouchActive = true;
        touchPinchState.singleLastX = e.touches[0].clientX;
        touchPinchState.singleLastY = e.touches[0].clientY;
    } else if (e.touches.length === 0) {
        touchPinchState.singleTouchActive = false;
        isPanning.value = false;
    }
}

onMounted(() => {
    preloadAgentSprites([1, 2, 3, 4, 5, 6, 7, 8]);
    loadLocalPreferences();
    pollTimer = setInterval(fetchOfficeState, 3000);
    choreographyTimer = setInterval(runChoreographyTick, 4600);

    const vp = officeViewportRef.value;
    if (vp) {
        vp.addEventListener('wheel', onOfficeWheel, { passive: false });
        vp.addEventListener('touchstart', onOfficeTouchStart, { passive: false });
        vp.addEventListener('touchmove', onOfficeTouchMove, { passive: false });
        vp.addEventListener('touchend', onOfficeTouchEnd, { passive: false });
        vp.addEventListener('touchcancel', onOfficeTouchEnd, { passive: false });
    }
});

onUnmounted(() => {
    if (pollTimer) clearInterval(pollTimer);
    if (choreographyTimer) clearInterval(choreographyTimer);
    if (toastTimer) clearTimeout(toastTimer);
    Object.values(overrideTimers).forEach((t) => clearTimeout(t));
    window.removeEventListener('pointermove', onOfficePointerMove);
    window.removeEventListener('pointerup', onOfficePointerUp);

    const vp = officeViewportRef.value;
    if (vp) {
        vp.removeEventListener('wheel', onOfficeWheel);
        vp.removeEventListener('touchstart', onOfficeTouchStart);
        vp.removeEventListener('touchmove', onOfficeTouchMove);
        vp.removeEventListener('touchend', onOfficeTouchEnd);
        vp.removeEventListener('touchcancel', onOfficeTouchEnd);
    }
});
</script>

<template>
    <Head title="Markas Agen - Ruang Periksa BPS" />

    <AppLayout wide>
        <div class="markas-agen-page bg-[#FCF9EA] text-[#302B2A]">
            <!-- Toast Notification Banner -->
            <transition
                enter-active-class="transition ease-out duration-200"
                enter-from-class="opacity-0 -translate-y-2"
                enter-to-class="opacity-100 translate-y-0"
                leave-active-class="transition ease-in duration-150"
                leave-from-class="opacity-100 translate-y-0"
                leave-to-class="opacity-0 -translate-y-2"
            >
                <div
                    v-if="toastMessage"
                    class="fixed top-20 right-6 z-50 flex items-center gap-2.5 bg-[#FFFFFF] border border-[#302B2A] rounded-full px-4 py-2 shadow-md text-xs font-semibold text-[#302B2A]"
                >
                    <span class="w-2.5 h-2.5 rounded-full bg-[#245F59]"></span>
                    <span>{{ toastMessage }}</span>
                </div>
            </transition>

            <!-- Mobile Segment Switcher (visible only on mobile lg:hidden) -->
            <div class="mobile-section-nav lg:hidden flex items-center p-1 bg-[#ede8dc] border border-[#D7D1C8] rounded-xl mb-3 gap-1">
                <button
                    type="button"
                    class="flex-1 py-1.5 px-2 rounded-lg text-[11px] sm:text-xs font-semibold flex items-center justify-center gap-1 transition-all cursor-pointer whitespace-nowrap"
                    :class="activeMobileTab === 'office' ? 'bg-[#FFFFFF] text-[#302B2A] shadow-xs' : 'text-[#5A5150] hover:text-[#302B2A]'"
                    @click="activeMobileTab = 'office'"
                >
                    <span>🏢 Ruang 3D</span>
                </button>
                <button
                    type="button"
                    class="flex-1 py-1.5 px-2 rounded-lg text-[11px] sm:text-xs font-semibold flex items-center justify-center gap-1 transition-all cursor-pointer whitespace-nowrap"
                    :class="activeMobileTab === 'agents' ? 'bg-[#FFFFFF] text-[#302B2A] shadow-xs' : 'text-[#5A5150] hover:text-[#302B2A]'"
                    @click="activeMobileTab = 'agents'"
                >
                    <span>🤖 5 Agen</span>
                    <span
                        v-if="hasActiveAudit"
                        class="w-1.5 h-1.5 rounded-full bg-[#245F59] animate-ping"
                    ></span>
                </button>
                <button
                    type="button"
                    class="flex-1 py-1.5 px-2 rounded-lg text-[11px] sm:text-xs font-semibold flex items-center justify-center gap-1 transition-all cursor-pointer whitespace-nowrap"
                    :class="activeMobileTab === 'logs' ? 'bg-[#FFFFFF] text-[#302B2A] shadow-xs' : 'text-[#5A5150] hover:text-[#302B2A]'"
                    @click="activeMobileTab = 'logs'"
                >
                    <span>📋 Aktivitas</span>
                </button>
            </div>

            <!-- Main 3-Column Editorial Workspace Container -->
            <div class="markas-grid">
                <!-- ========================================================= -->
                <!-- 1. KOLOM KIRI: "Status & Daftar Agen"                     -->
                <!-- ========================================================= -->
                <aside
                    class="markas-side bg-[#FCF9EA] border border-[#D7D1C8] rounded-2xl sm:rounded-[20px] p-3 sm:p-3.5 flex-col gap-3"
                    :class="[activeMobileTab === 'agents' ? 'flex' : 'hidden lg:flex']"
                >
                    <div class="flex items-center justify-between gap-2 pb-2.5 border-b border-[#D7D1C8]">
                        <div class="min-w-0">
                            <h2 class="mk-panel-title font-['Outfit'] font-semibold text-[#302B2A] truncate">
                                Status & Daftar Agen
                            </h2>
                            <p class="text-[11px] text-[#5A5150]">
                                {{
                                    hasActiveAudit
                                        ? `${activelyWorkingCount} agen sedang bekerja`
                                        : `0 bekerja · ${readyAgentsCount}/5 agen siaga`
                                }}
                            </p>
                        </div>
                        <button
                            type="button"
                            :disabled="pingingAll"
                            class="btn-coral-action whitespace-nowrap shrink-0 text-[11px] font-semibold rounded-full px-3 py-1.5 border border-[#E88D8D] hover:bg-[#ff9191] hover:border-[#302B2A] transition-all cursor-pointer"
                            @click="pingAllAgents"
                        >
                            {{ pingingAll ? 'Menguji...' : 'Ping 5 Agen' }}
                        </button>
                    </div>

                    <!-- 5 Agent Slot Cards -->
                    <div class="flex flex-col gap-2.5">
                        <div
                            v-for="agent in normalizedAgents"
                            :key="agent.slot"
                            class="bg-[#FFFFFF] border rounded-2xl p-3 transition-all duration-150 cursor-pointer"
                            :class="
                                selectedSlot === agent.slot
                                    ? 'border-[#302B2A] ring-2 ring-[#FFA4A4] shadow-xs'
                                    : 'border-[#D7D1C8] hover:border-[#302B2A]'
                            "
                            @click="selectedSlot = agent.slot"
                        >
                            <!-- Card Top Row: 3D Avatar + Name/Model + Accurate Real Status Badge -->
                            <div class="flex items-start justify-between gap-2">
                                <div class="flex items-center gap-2.5 min-w-0">
                                    <div
                                        class="w-11 h-11 rounded-xl bg-[#FCF9EA] border border-[#D7D1C8] shrink-0 overflow-hidden relative flex items-center justify-center"
                                        title="Klik untuk mengganti karakter 3D"
                                        @click.stop="openSkinPickerForSlot(agent.slot)"
                                    >
                                        <div
                                            class="w-13 h-13"
                                            :style="{
                                                backgroundImage: `url('/assets/office/agent-${agent.skinId}.png')`,
                                                backgroundSize: '400% 200%',
                                                backgroundPosition: '0% 0%',
                                                backgroundRepeat: 'no-repeat',
                                            }"
                                        ></div>
                                        <span
                                            class="absolute bottom-0 right-0 bg-[#302B2A] text-[#FCF9EA] font-['JetBrains_Mono'] text-[8.5px] font-bold px-1 rounded-tl"
                                        >
                                            #{{ agent.slot }}
                                        </span>
                                    </div>

                                    <div class="min-w-0">
                                        <h3 class="mk-card-title font-bold text-[#302B2A] truncate">
                                            {{ agent.name }}
                                        </h3>
                                        <p class="font-['JetBrains_Mono'] text-[10.5px] text-[#5A5150] truncate">
                                            {{ agent.model || 'gemini-2.5-flash' }} · {{ agent.skinMeta.name }}
                                        </p>
                                    </div>
                                </div>

                                <span
                                    class="text-[10px] font-semibold px-2 py-0.5 rounded-full border whitespace-nowrap shrink-0"
                                    :class="agent.badgeStyle"
                                >
                                    {{ agent.badgeLabel }}
                                </span>
                            </div>

                            <!-- Progress Bar (0% when idle, real active publication progress when auditing) -->
                            <div class="mt-2">
                                <div class="flex items-center justify-between text-[10px] mb-1">
                                    <span class="text-[#5A5150]">Tugas Saat Ini</span>
                                    <span class="font-['JetBrains_Mono'] tabular-nums font-semibold text-[#302B2A]">
                                        {{ agent.progressLabel }}
                                    </span>
                                </div>
                                <div class="w-full h-1.5 rounded-full bg-[#FCF9EA] border border-[#D7D1C8] overflow-hidden">
                                    <div
                                        class="h-full bg-[#245F59] rounded-full transition-all duration-500"
                                        :style="{ width: `${agent.progressPct}%` }"
                                    ></div>
                                </div>
                            </div>

                            <!-- Active Publication & Real-Time Activity Box -->
                            <div class="mt-2 bg-[#FCF9EA] border border-[#D7D1C8] rounded-xl px-2.5 py-1.5">
                                <p
                                    class="text-[11px] font-semibold truncate"
                                    :class="hasActiveAudit || demoMode ? 'text-[#302B2A]' : 'text-[#5A5150]'"
                                >
                                    {{ agent.activePubTitle }}
                                </p>
                                <p class="text-[10.5px] text-[#5A5150] truncate mt-0.5">
                                    {{ agent.statusText }}
                                </p>
                            </div>

                            <!-- 4-Column Historical Metrics & Action Row -->
                            <div class="mt-2.5 pt-2 border-t border-[#D7D1C8] flex items-center justify-between gap-1">
                                <div class="grid grid-cols-3 gap-2 min-w-0 flex-1 font-['JetBrains_Mono'] tabular-nums">
                                    <div title="Total riwayat halaman selesai diperiksa oleh agen ini">
                                        <span class="block text-[9px] font-sans text-[#5A5150] leading-tight">Riwayat</span>
                                        <strong class="text-[11px] text-[#302B2A] whitespace-nowrap">{{ agent.completed_pages }} hal</strong>
                                    </div>
                                    <div title="Total panggilan API sepanjang waktu">
                                        <span class="block text-[9px] font-sans text-[#5A5150] leading-tight">Total API</span>
                                        <strong class="text-[11px] text-[#302B2A] whitespace-nowrap">{{ agent.total_calls }}</strong>
                                    </div>
                                    <div title="Rata-rata waktu respons API per halaman">
                                        <span class="block text-[9px] font-sans text-[#5A5150] leading-tight">Rata-rata</span>
                                        <strong class="text-[11px] text-[#302B2A] whitespace-nowrap">
                                            {{ agent.avg_duration_ms ? `${(agent.avg_duration_ms / 1000).toFixed(1)}s` : '-' }}
                                        </strong>
                                    </div>
                                </div>

                                <div class="flex items-center gap-1 shrink-0" @click.stop>
                                    <button
                                        type="button"
                                        class="btn-secondary-pill whitespace-nowrap text-[10px] font-semibold px-2 py-1 rounded-full border border-[#D7D1C8] cursor-pointer"
                                        title="Ganti karakter 3D"
                                        @click="openSkinPickerForSlot(agent.slot)"
                                    >
                                        Skin
                                    </button>
                                    <button
                                        type="button"
                                        :disabled="pingingAgentId === agent.id"
                                        class="btn-secondary-pill whitespace-nowrap text-[10px] font-semibold px-2.5 py-1 rounded-full border border-[#D7D1C8] cursor-pointer"
                                        @click="pingSingleAgent(agent)"
                                    >
                                        {{ pingingAgentId === agent.id ? '...' : 'Ping' }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </aside>

                <!-- ========================================================= -->
                <!-- 2. KOLOM TENGAH (LARGE): "Ruang Operasi 5 Agen AI"         -->
                <!-- ========================================================= -->
                <section
                    class="markas-center min-w-0 bg-[#FCF9EA] border border-[#D7D1C8] rounded-2xl sm:rounded-[20px] p-2.5 sm:p-5 flex-col gap-3"
                    :class="[activeMobileTab === 'office' ? 'flex' : 'hidden lg:flex']"
                >
                    <!-- Center Header Bar -->
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2.5 pb-2.5 border-b border-[#D7D1C8]">
                        <div>
                            <span class="text-[11px] sm:text-xs font-semibold text-[#5A5150] block leading-tight">AI 3D Office</span>
                            <div class="flex items-center gap-2 flex-wrap mt-0.5">
                                <h1 class="mk-main-title font-['Outfit'] font-semibold text-[#302B2A]">
                                    Ruang Operasi 5 Agen AI
                                </h1>

                                <!-- Accurate Real-Time Audit Status Badge -->
                                <span
                                    v-if="hasActiveAudit"
                                    class="inline-flex items-center gap-1.5 bg-[#BADFDB] text-[#245F59] border border-[#96C9C3] rounded-full px-2.5 sm:px-3 py-0.5 text-[10.5px] sm:text-[11px] font-semibold font-['JetBrains_Mono'] tabular-nums"
                                >
                                    <span class="w-1.5 h-1.5 sm:w-2 sm:h-2 rounded-full bg-[#245F59] animate-ping"></span>
                                    <span class="hidden sm:inline">PEMERIKSAAN AKTIF ({{ activelyWorkingCount }} BEKERJA · {{ activePubCompletedPages }}/{{ activePubTotalPages }} HAL)</span>
                                    <span class="sm:hidden">AKTIF: {{ activelyWorkingCount }} BEKERJA ({{ activePubCompletedPages }}/{{ activePubTotalPages }} HAL)</span>
                                </span>
                                <span
                                    v-else-if="demoMode"
                                    class="inline-flex items-center gap-1.5 bg-[#FFBDBD] text-[#302B2A] border border-[#E88D8D] rounded-full px-2.5 sm:px-3 py-0.5 text-[10.5px] sm:text-[11px] font-semibold font-['JetBrains_Mono'] tabular-nums"
                                >
                                    SIMULASI DEMO
                                </span>
                                <span
                                    v-else
                                    class="inline-flex items-center gap-1.5 bg-[#FFFFFF] text-[#5A5150] border border-[#D7D1C8] rounded-full px-2.5 sm:px-3 py-0.5 text-[10px] sm:text-[11px] font-semibold font-['JetBrains_Mono'] tabular-nums"
                                >
                                    <span class="w-1.5 h-1.5 rounded-full bg-[#D7D1C8]"></span>
                                    <span class="hidden sm:inline">TIDAK ADA PEMERIKSAAN AKTIF · {{ readyAgentsCount }}/5 SIAGA (RIWAYAT: {{ totalProcessedPagesDisplay }} HAL)</span>
                                    <span class="sm:hidden">{{ readyAgentsCount }}/5 SIAGA · {{ totalProcessedPagesDisplay }} HAL</span>
                                </span>
                            </div>
                        </div>

                        <!-- Top Right Controls (Horizontally scrollable on mobile) -->
                        <div class="flex items-center gap-1.5 overflow-x-auto w-full sm:w-auto pb-1 -mx-0.5 px-0.5 sm:mx-0 sm:px-0 no-scrollbar shrink-0">
                            <button
                                type="button"
                                class="whitespace-nowrap text-[11px] sm:text-[11.5px] font-semibold rounded-full px-2.5 sm:px-3 py-1.5 border transition-colors cursor-pointer shrink-0"
                                :class="
                                    demoMode
                                        ? 'btn-coral-action border-[#302B2A]'
                                        : 'btn-secondary-pill border-[#D7D1C8]'
                                "
                                title="Aktifkan hanya jika ingin melihat contoh simulasi gerakan tanpa pemeriksaan asli"
                                @click="toggleDemoMode"
                            >
                                {{ demoMode ? '■ Matikan Demo' : '▶ Simulasi Demo' }}
                            </button>

                            <button
                                type="button"
                                class="btn-secondary-pill whitespace-nowrap border border-[#D7D1C8] rounded-full px-2.5 sm:px-3 py-1.5 text-[11px] sm:text-[11.5px] font-semibold font-['JetBrains_Mono'] tabular-nums transition-colors cursor-pointer shrink-0"
                                @click="liveSyncEnabled = !liveSyncEnabled"
                            >
                                Live Sync: {{ liveSyncEnabled ? '3s' : 'Pause' }}
                            </button>

                            <button
                                type="button"
                                class="btn-secondary-pill whitespace-nowrap border border-[#D7D1C8] rounded-full px-2.5 sm:px-3 py-1.5 text-[11px] sm:text-[11.5px] font-semibold transition-colors cursor-pointer shrink-0"
                                @click="openSkinPickerForSlot(selectedSlot)"
                            >
                                Pilih Skin
                            </button>

                            <button
                                type="button"
                                class="whitespace-nowrap text-[11px] sm:text-[11.5px] font-semibold rounded-full px-2.5 sm:px-3 py-1.5 border transition-colors cursor-pointer shrink-0"
                                :class="
                                    debugMode
                                        ? 'btn-cocoa-ink border-[#302B2A]'
                                        : 'btn-secondary-pill border-[#D7D1C8]'
                                "
                                title="Tampilkan jalur waypoint koridor isometric, frame sprite 1-8, dan arah jalan"
                                @click="debugMode = !debugMode"
                            >
                                {{ debugMode ? 'Debug: ON' : 'Debug Jalur' }}
                            </button>

                            <button
                                type="button"
                                class="whitespace-nowrap text-[11px] sm:text-[11.5px] font-semibold rounded-full px-2.5 sm:px-3 py-1.5 border transition-colors cursor-pointer shrink-0"
                                :class="
                                    calibrationMode
                                        ? 'btn-coral-action border-[#302B2A]'
                                        : 'btn-secondary-pill border-[#D7D1C8]'
                                "
                                @click="calibrationMode = !calibrationMode"
                            >
                                {{ calibrationMode ? 'Selesai' : 'Kalibrasi' }}
                            </button>
                        </div>
                    </div>

                    <!-- Demo Mode Notice Banner -->
                    <div
                        v-if="demoMode"
                        class="bg-[#FFBDBD]/50 border border-[#302B2A] rounded-2xl px-3.5 py-2 flex flex-wrap items-center justify-between gap-2 text-xs"
                    >
                        <div class="flex items-center gap-2 text-[#302B2A]">
                            <span class="px-2 py-0.5 rounded-md bg-[#302B2A] text-[#FCF9EA] font-bold">SIMULASI DEMO</span>
                            <span>
                                Anda sedang melihat contoh animasi simulasi. Klik <strong>"Matikan Simulasi Demo"</strong> untuk kembali ke status real-time asli.
                            </span>
                        </div>
                    </div>

                    <!-- Calibration Toolbar & Typing / Working Alignment Panel -->
                    <div
                        v-if="calibrationMode"
                        class="bg-[#FFFFFF] border border-[#302B2A] rounded-2xl p-3 flex flex-col gap-2.5 text-xs"
                    >
                        <div class="flex flex-wrap items-center justify-between gap-2 pb-2 border-b border-[#D7D1C8]">
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="px-2 py-0.5 rounded-md bg-[#FFA4A4] text-[#302B2A] font-bold">
                                    ADMIN KALIBRASI
                                </span>
                                <div class="inline-flex rounded-full bg-[#FCF9EA] border border-[#D7D1C8] p-0.5">
                                    <button
                                        type="button"
                                        class="px-2.5 py-0.5 rounded-full text-[11px] font-semibold cursor-pointer transition-colors"
                                        :class="
                                            calibrationTab === 'typing'
                                                ? 'bg-[#302B2A] text-[#FCF9EA]'
                                                : 'text-[#5A5150] hover:text-[#302B2A]'
                                        "
                                        @click="calibrationTab = 'typing'"
                                    >
                                        Typing / Working Alignment
                                    </button>
                                    <button
                                        type="button"
                                        class="px-2.5 py-0.5 rounded-full text-[11px] font-semibold cursor-pointer transition-colors"
                                        :class="
                                            calibrationTab === 'desk'
                                                ? 'bg-[#302B2A] text-[#FCF9EA]'
                                                : 'text-[#5A5150] hover:text-[#302B2A]'
                                        "
                                        @click="calibrationTab = 'desk'"
                                    >
                                        Posisi Kursi & Arah
                                    </button>
                                </div>

                                <!-- Slot Selector Pills (#1..#5) -->
                                <div class="flex items-center gap-1 ml-1">
                                    <button
                                        v-for="s in [1, 2, 3, 4, 5]"
                                        :key="'calib-slot-' + s"
                                        type="button"
                                        class="px-2 py-0.5 rounded-full font-mono text-[10.5px] font-bold border cursor-pointer"
                                        :class="
                                            selectedSlot === s
                                                ? 'bg-[#BADFDB] text-[#245F59] border-[#245F59]'
                                                : 'bg-[#FCF9EA] text-[#5A5150] border-[#D7D1C8]'
                                        "
                                        @click="selectedSlot = s"
                                    >
                                        #{{ s }}
                                    </button>
                                </div>
                            </div>

                            <div class="flex items-center gap-2">
                                <button
                                    type="button"
                                    class="btn-secondary-pill border border-[#D7D1C8] rounded-full px-3 py-1 font-semibold cursor-pointer"
                                    @click="toggleDeskFacing(selectedSlot)"
                                >
                                    Arah Meja #{{ selectedSlot }}: {{ deskPositions[selectedSlot].facing === 'right' ? 'Kanan' : 'Kiri' }}
                                </button>
                                <button
                                    type="button"
                                    class="bg-[#FFFFFF] text-[#A5363F] border border-[#FFA4A4] rounded-full px-3 py-1 font-semibold cursor-pointer"
                                    @click="resetDeskPositions"
                                >
                                    Reset Default
                                </button>
                            </div>
                        </div>

                        <!-- TAB 1: TYPING / WORKING ALIGNMENT -->
                        <div
                            v-if="calibrationTab === 'typing' && typingCalibration[selectedSlot]"
                            class="grid grid-cols-1 md:grid-cols-12 gap-3 items-center"
                        >
                            <!-- Live 8-Frame Sprite Preview Card -->
                            <div
                                class="md:col-span-3 bg-[#FCF9EA] border border-[#D7D1C8] rounded-xl p-2 flex items-center gap-2.5"
                            >
                                <div
                                    class="w-14 h-14 rounded-xl bg-[#FFFFFF] border border-[#302B2A] shrink-0 overflow-hidden flex items-center justify-center"
                                >
                                    <div
                                        class="w-14 h-14"
                                        :style="{
                                            backgroundImage: `url('/assets/office/typing/agent-${slotSkins[selectedSlot] || selectedSlot}-typing.png')`,
                                            backgroundSize: '400% 200%',
                                            backgroundPosition:
                                                TYPING_FRAME_POSITIONS[
                                                    ((agentPathSnapshots[selectedSlot]?.walkFrame || 1) - 1) % 8
                                                ] || '0% 0%',
                                            backgroundRepeat: 'no-repeat',
                                        }"
                                    ></div>
                                </div>
                                <div class="min-w-0 flex-1">
                                    <div class="font-bold text-[#302B2A] text-[11px] truncate">
                                        Agen #{{ selectedSlot }} ({{ DEFAULT_DESK_POSITIONS[selectedSlot].label }})
                                    </div>
                                    <div class="font-mono text-[10px] text-[#245F59] font-semibold mt-0.5">
                                        Frame Aktif: {{ agentPathSnapshots[selectedSlot]?.walkFrame || 1 }} / 8
                                    </div>
                                    <div class="flex items-center gap-1 mt-1 flex-wrap">
                                        <span class="px-1.5 py-0.2 rounded bg-[#245F59] text-[#FFFFFF] font-mono text-[8.5px]">
                                            L/R: Tangan
                                        </span>
                                        <span class="px-1.5 py-0.2 rounded bg-[#85500F] text-[#FFFFFF] font-mono text-[8.5px]">
                                            S: Kursi
                                        </span>
                                        <span class="px-1.5 py-0.2 rounded bg-[#FFA4A4] text-[#302B2A] font-mono text-[8.5px] font-bold">
                                            KB: Keyboard
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <!-- Sliders for Seat, Offset X/Y, Scale, Speed FPS, Frame Lock, Pivot, Direction -->
                            <div class="md:col-span-9 grid grid-cols-2 sm:grid-cols-4 gap-2.5">
                                <label class="flex flex-col gap-0.5">
                                    <span class="text-[10px] font-semibold text-[#5A5150] flex justify-between">
                                        <span>Offset X (px)</span>
                                        <strong class="font-mono text-[#302B2A]">{{ typingCalibration[selectedSlot].offsetX }}px</strong>
                                    </span>
                                    <input
                                        v-model.number="typingCalibration[selectedSlot].offsetX"
                                        type="range"
                                        min="-40"
                                        max="40"
                                        step="1"
                                        class="accent-[#245F59] cursor-pointer"
                                        @input="handleTypingCalibrationChange(selectedSlot)"
                                    />
                                </label>

                                <label class="flex flex-col gap-0.5">
                                    <span class="text-[10px] font-semibold text-[#5A5150] flex justify-between">
                                        <span>Offset Y (px)</span>
                                        <strong class="font-mono text-[#302B2A]">{{ typingCalibration[selectedSlot].offsetY }}px</strong>
                                    </span>
                                    <input
                                        v-model.number="typingCalibration[selectedSlot].offsetY"
                                        type="range"
                                        min="-40"
                                        max="40"
                                        step="1"
                                        class="accent-[#245F59] cursor-pointer"
                                        @input="handleTypingCalibrationChange(selectedSlot)"
                                    />
                                </label>

                                <label class="flex flex-col gap-0.5">
                                    <span class="text-[10px] font-semibold text-[#5A5150] flex justify-between">
                                        <span>Skala Karakter</span>
                                        <strong class="font-mono text-[#302B2A]">{{ Number(typingCalibration[selectedSlot].scale).toFixed(2) }}x</strong>
                                    </span>
                                    <input
                                        v-model.number="typingCalibration[selectedSlot].scale"
                                        type="range"
                                        min="0.75"
                                        max="1.30"
                                        step="0.01"
                                        class="accent-[#245F59] cursor-pointer"
                                        @input="handleTypingCalibrationChange(selectedSlot)"
                                    />
                                </label>

                                <label class="flex flex-col gap-0.5">
                                    <span class="text-[10px] font-semibold text-[#5A5150] flex justify-between">
                                        <span>Kecepatan Typing</span>
                                        <strong class="font-mono text-[#302B2A]">{{ Number(typingCalibration[selectedSlot].fps).toFixed(1) }} FPS</strong>
                                    </span>
                                    <input
                                        v-model.number="typingCalibration[selectedSlot].fps"
                                        type="range"
                                        min="4"
                                        max="16"
                                        step="0.5"
                                        class="accent-[#245F59] cursor-pointer"
                                        @input="handleTypingCalibrationChange(selectedSlot)"
                                    />
                                </label>

                                <label class="flex flex-col gap-0.5">
                                    <span class="text-[10px] font-semibold text-[#5A5150] flex justify-between">
                                        <span>Pusat Keyboard X</span>
                                        <strong class="font-mono text-[#302B2A]">{{ Number(typingCalibration[selectedSlot].keyboardX).toFixed(1) }}%</strong>
                                    </span>
                                    <input
                                        v-model.number="typingCalibration[selectedSlot].keyboardX"
                                        type="range"
                                        min="15"
                                        max="85"
                                        step="0.1"
                                        class="accent-[#85500F] cursor-pointer"
                                        @input="handleTypingCalibrationChange(selectedSlot)"
                                    />
                                </label>

                                <label class="flex flex-col gap-0.5">
                                    <span class="text-[10px] font-semibold text-[#5A5150] flex justify-between">
                                        <span>Pusat Keyboard Y</span>
                                        <strong class="font-mono text-[#302B2A]">{{ Number(typingCalibration[selectedSlot].keyboardY).toFixed(1) }}%</strong>
                                    </span>
                                    <input
                                        v-model.number="typingCalibration[selectedSlot].keyboardY"
                                        type="range"
                                        min="15"
                                        max="85"
                                        step="0.1"
                                        class="accent-[#85500F] cursor-pointer"
                                        @input="handleTypingCalibrationChange(selectedSlot)"
                                    />
                                </label>

                                <label class="flex flex-col gap-0.5">
                                    <span class="text-[10px] font-semibold text-[#5A5150]">Frame Preview (1-8)</span>
                                    <select
                                        v-model.number="typingCalibration[selectedSlot].lockedFrame"
                                        class="bg-[#FCF9EA] border border-[#D7D1C8] rounded-lg px-2 py-1 text-[11px] font-semibold text-[#302B2A]"
                                        @change="handleTypingCalibrationChange(selectedSlot)"
                                    >
                                        <option :value="0">Loop Otomatis (1 → 8)</option>
                                        <option v-for="f in 8" :key="'lock-f-' + f" :value="f">
                                            Kunci Frame {{ f }} / 8
                                        </option>
                                    </select>
                                </label>

                                <label class="flex flex-col gap-0.5">
                                    <span class="text-[10px] font-semibold text-[#5A5150]">Orientasi Duduk</span>
                                    <select
                                        v-model="typingCalibration[selectedSlot].direction"
                                        class="bg-[#FCF9EA] border border-[#D7D1C8] rounded-lg px-2 py-1 text-[11px] font-semibold text-[#302B2A]"
                                        @change="handleTypingCalibrationChange(selectedSlot)"
                                    >
                                        <option value="toward-monitor">Menghadap Monitor (Native)</option>
                                        <option value="back-three-quarter">3/4 Belakang (Monitor Depan)</option>
                                        <option value="flip-horizontal">Balik Horizontal (Mirror)</option>
                                    </select>
                                </label>
                            </div>
                        </div>

                        <!-- TAB 2: DESK SEAT COORDINATES -->
                        <div v-else class="flex flex-wrap items-center justify-between gap-2 text-[11px] text-[#5A5150]">
                            <span>
                                Geser karakter langsung di atas gambar office untuk mengatur titik duduk kursi. Koordinat kursi Agen #{{ selectedSlot }}:
                                <strong class="font-mono text-[#302B2A]">
                                    Left {{ deskPositions[selectedSlot].left.toFixed(1) }}%, Top {{ deskPositions[selectedSlot].top.toFixed(1) }}%
                                </strong>
                            </span>
                        </div>
                    </div>

                    <!-- ===================================================== -->
                    <!-- LARGE INTERACTIVE ISOMETRIC 3D OFFICE DIORAMA (3:2)   -->
                    <!-- ===================================================== -->
                    <div
                        ref="officeViewportRef"
                        class="office-diorama-stage relative w-full rounded-[18px] select-none"
                        :class="[
                            cameraZoom > 1.01 && !calibrationMode
                                ? isPanning
                                    ? 'cursor-grabbing'
                                    : 'cursor-grab'
                                : '',
                        ]"
                        @pointerdown="onOfficePointerDown"
                        @dblclick="onOfficeDblClick"
                    >
                        <!-- Zoomable & Pannable Isometric World Canvas -->
                        <div
                            class="office-camera-canvas absolute inset-0 w-full h-full"
                            :class="{ 'office-camera-smooth': !isPanning && !isTouchPinching }"
                            :style="{
                                transformOrigin: '0 0',
                                transform: `translate3d(${cameraPanX}px, ${cameraPanY}px, 0) scale(${cameraZoom})`,
                            }"
                        >
                            <img
                                :src="OFFICE_BG_URL"
                                alt="Isometric 3D AI Agent Office BPS"
                                class="absolute inset-0 w-full h-full object-contain select-none pointer-events-none"
                                draggable="false"
                            />

                            <!-- Animated SVG Data Lines & Optional Debug Corridor Waypoint Graph -->
                            <svg
                                class="absolute inset-0 w-full h-full pointer-events-none z-10"
                                viewBox="0 0 100 100"
                                preserveAspectRatio="none"
                            >
                                <!-- Standard Subtle Desk-to-Server Telemetry Lines -->
                                <g v-for="agent in normalizedAgents" :key="'line-' + agent.slot">
                                    <line
                                        v-if="agent.behaviorState !== 'offline'"
                                        :x1="agent.deskPosition.left"
                                        :y1="agent.deskPosition.top"
                                        :x2="SERVER_POSITION.left"
                                        :y2="SERVER_POSITION.top"
                                        :stroke="
                                            selectedSlot === agent.slot
                                                ? '#FFA4A4'
                                                : agent.behaviorState === 'analyzing' || agent.behaviorState === 'working'
                                                  ? '#245F59'
                                                  : '#D7D1C8'
                                        "
                                        :stroke-width="selectedSlot === agent.slot ? '0.4' : '0.24'"
                                        stroke-dasharray="1.1 1.1"
                                        :class="{
                                            'office-data-line':
                                                agent.behaviorState === 'analyzing' ||
                                                agent.behaviorState === 'working' ||
                                                agent.behaviorState.startsWith('walking'),
                                        }"
                                        :opacity="selectedSlot === agent.slot ? '0.85' : '0.45'"
                                    />
                                </g>

                                <!-- DEBUG / CALIBRATION OVERLAY: Isometric Floor Waypoint Graph & Active Travel Paths -->
                                <g v-if="debugMode || calibrationMode">
                                    <!-- Walkable Corridor Edges -->
                                    <line
                                        v-for="edge in waypointEdgeLines"
                                        :key="edge.id"
                                        :x1="edge.x1"
                                        :y1="edge.y1"
                                        :x2="edge.x2"
                                        :y2="edge.y2"
                                        stroke="#85500F"
                                        stroke-width="0.22"
                                        stroke-dasharray="0.6 0.6"
                                        opacity="0.55"
                                    />

                                    <!-- Active Agent Travel Route Polylines -->
                                    <g v-for="slot in [1, 2, 3, 4, 5]" :key="'active-route-' + slot">
                                        <polyline
                                            v-if="agentPathSnapshots[slot]?.waypoints?.length"
                                            :points="
                                                [
                                                    `${agentPathSnapshots[slot].currentLeft},${agentPathSnapshots[slot].currentTop}`,
                                                    ...agentPathSnapshots[slot].waypoints.map((w) => `${w.left},${w.top}`),
                                                ].join(' ')
                                            "
                                            fill="none"
                                            :stroke="selectedSlot === slot ? '#A5363F' : '#245F59'"
                                            stroke-width="0.48"
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                        />
                                    </g>

                                    <!-- Waypoint Nodes -->
                                    <g v-for="node in waypointNodeList" :key="node.id">
                                        <circle
                                            :cx="node.left"
                                            :cy="node.top"
                                            r="0.65"
                                            fill="#FCF9EA"
                                            stroke="#302B2A"
                                            stroke-width="0.22"
                                        />
                                    </g>
                                </g>
                            </svg>

                            <!-- Landmark Pill: Central Server -->
                            <div
                                class="absolute z-10 -translate-x-1/2 -translate-y-1/2 pointer-events-none"
                                :style="{ top: `${SERVER_LANDMARK.top}%`, left: `${SERVER_LANDMARK.left}%` }"
                            >
                                <div
                                    class="px-2 py-0.5 rounded-full bg-[#FFFFFF]/90 border border-[#D7D1C8] text-[9px] font-['JetBrains_Mono'] font-semibold text-[#245F59] shadow-xs flex items-center gap-1"
                                >
                                    <span
                                        class="w-1.5 h-1.5 rounded-full"
                                        :class="hasActiveAudit || demoMode ? 'bg-[#245F59] animate-ping' : 'bg-[#5A5150]'"
                                    ></span>
                                    <span>SERVER</span>
                                </div>
                            </div>

                            <!-- Landmark Pill: Water Dispenser & Coffee Area -->
                            <div
                                class="absolute z-10 -translate-x-1/2 -translate-y-1/2 pointer-events-none"
                                :style="{ top: `${COFFEE_LANDMARK.top}%`, left: `${COFFEE_LANDMARK.left}%` }"
                            >
                                <div
                                    class="px-2 py-0.5 rounded-full bg-[#FCF9EA]/95 border border-[#D7D1C8] text-[9px] font-semibold text-[#85500F] shadow-xs"
                                >
                                    ☕ Kopi
                                </div>
                            </div>

                            <!-- 5 Animated Character Sprites on the Isometric Floor (Layer 2: Body, Layer 4: Hands, Layer 6: UI) -->
                            <AgentSprite
                                v-for="agent in normalizedAgents"
                                :key="'sprite-' + agent.slot"
                                :agent="agent"
                                :slot-number="agent.slot"
                                :skin-id="agent.skinId"
                                :desk-position="agent.deskPosition"
                                :typing-calibration="typingCalibration[agent.slot]"
                                :server-position="SERVER_POSITION"
                                :coffee-position="COFFEE_POSITION"
                                :behavior-state="agent.behaviorState"
                                :status-text="agent.statusText"
                                :is-selected="selectedSlot === agent.slot"
                                :calibration-mode="calibrationMode"
                                :debug-mode="debugMode"
                                @select="selectedSlot = agent.slot"
                                @update-position="handleUpdatePosition"
                                @path-update="handlePathUpdate"
                            />

                            <!-- Layer 3: Foreground Chair Backrest & Armrest Occlusion Masks per Workstation -->
                            <img
                                v-for="slot in [1, 2, 3, 4, 5]"
                                :key="'chair-fg-' + slot"
                                :src="OFFICE_CHAIRS_FG_URL"
                                alt=""
                                aria-hidden="true"
                                class="
                                    absolute inset-0 w-full h-full object-contain
                                    select-none pointer-events-none
                                "
                                :style="{
                                    zIndex: 21 + Math.round(deskPositions[slot].top),
                                    clipPath: `inset(${DEFAULT_DESK_POSITIONS[slot].chairClipInset})`,
                                }"
                                draggable="false"
                            />

                            <!-- Calibration Mode: Marker Pusat Keyboard (KB) on Existing Office Desks -->
                            <template v-if="calibrationMode || debugMode">
                                <div
                                    v-for="slot in [1, 2, 3, 4, 5]"
                                    :key="'kb-marker-' + slot"
                                    class="absolute z-65 -translate-x-1/2 -translate-y-1/2 pointer-events-none flex flex-col items-center"
                                    :style="{
                                        left: `${typingCalibration[slot]?.keyboardX ?? DEFAULT_TYPING_CALIBRATION[slot]?.keyboardX ?? 50}%`,
                                        top: `${typingCalibration[slot]?.keyboardY ?? DEFAULT_TYPING_CALIBRATION[slot]?.keyboardY ?? 50}%`,
                                    }"
                                >
                                    <span class="w-3.5 h-3.5 rounded-full bg-amber-400 border-2 border-slate-900 shadow-sm flex items-center justify-center text-[6px] font-black text-slate-900">
                                        KB
                                    </span>
                                    <span class="mt-0.5 px-1 py-0 rounded bg-slate-900/90 text-[7px] font-mono text-amber-300 whitespace-nowrap">
                                        KB{{ slot }}
                                    </span>
                                </div>
                            </template>
                        </div>

                        <!-- ================================================= -->
                        <!-- FLOATING CAMERA ZOOM & PAN HUD (CRISP 1:1 OVERLAY)-->
                        <!-- ================================================= -->
                        <div
                            class="office-hud-controls absolute bottom-2.5 right-2.5 z-90 flex items-center gap-1.5 bg-[#FFFFFF]/95 border border-[#D7D1C8] rounded-full px-2 py-1 shadow-xs"
                        >
                            <span
                                class="office-hud-guide hidden sm:inline-block font-medium text-[#5A5150] pr-1.5 border-r border-[#D7D1C8]"
                            >
                                {{
                                    cameraZoom > 1.01
                                        ? 'Geser area kantor · Klik % reset'
                                        : 'Ctrl+Scroll / 2 Jari untuk Zoom'
                                }}
                            </span>

                            <button
                                type="button"
                                :disabled="cameraZoom <= MIN_OFFICE_ZOOM"
                                class="office-hud-btn-step rounded-full border border-[#D7D1C8] bg-[#FCF9EA] hover:border-[#302B2A] disabled:opacity-40 flex items-center justify-center font-bold text-[#302B2A] cursor-pointer transition-colors shrink-0"
                                title="Perkecil tampilan kantor (Zoom Out)"
                                @click.stop="zoomOfficeByStep(-0.25)"
                            >
                                −
                            </button>

                            <button
                                type="button"
                                class="office-hud-pct-badge tabular-nums font-semibold rounded-full border transition-colors cursor-pointer shrink-0"
                                :class="
                                    cameraZoom > 1.01
                                        ? 'bg-[#BADFDB] text-[#245F59] border-[#96C9C3]'
                                        : 'bg-[#FFFFFF] text-[#302B2A] border-[#D7D1C8]'
                                "
                                title="Klik untuk mengembalikan zoom ke 100%"
                                @click.stop="resetOfficeCamera"
                            >
                                {{ Math.round(cameraZoom * 100) }}%
                            </button>

                            <button
                                type="button"
                                :disabled="cameraZoom >= MAX_OFFICE_ZOOM"
                                class="office-hud-btn-step rounded-full border border-[#D7D1C8] bg-[#FCF9EA] hover:border-[#302B2A] disabled:opacity-40 flex items-center justify-center font-bold text-[#302B2A] cursor-pointer transition-colors shrink-0"
                                title="Perbesar tampilan kantor (Zoom In)"
                                @click.stop="zoomOfficeByStep(0.25)"
                            >
                                +
                            </button>
                        </div>
                    </div>

                    <!-- ===================================================== -->
                    <!-- BOTTOM SELECTED AGENT INSPECTOR & POSE TEST BAR       -->
                    <!-- ===================================================== -->
                    <div
                        v-if="selectedAgent"
                        class="bg-[#FFFFFF] border border-[#D7D1C8] rounded-2xl p-3.5 flex flex-col gap-2.5"
                    >
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div class="flex items-center gap-3 min-w-0">
                                <div
                                    class="w-11 h-11 rounded-xl bg-[#FCF9EA] border border-[#302B2A] shrink-0 overflow-hidden flex items-center justify-center"
                                >
                                    <div
                                        class="w-13 h-13"
                                        :style="{
                                            backgroundImage: `url('/assets/office/agent-${selectedAgent.skinId}.png')`,
                                            backgroundSize: '400% 200%',
                                            backgroundPosition: '0% 100%',
                                            backgroundRepeat: 'no-repeat',
                                        }"
                                    ></div>
                                </div>
                                <div class="min-w-0">
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <span
                                            class="px-2 py-0.5 rounded-md bg-[#FFA4A4] text-[#302B2A] font-['JetBrains_Mono'] text-[11px] font-bold"
                                        >
                                            SLOT #{{ selectedAgent.slot }}
                                        </span>
                                        <h3 class="mk-card-title font-['Outfit'] font-semibold text-[#302B2A]">
                                            {{ selectedAgent.name }}
                                        </h3>
                                        <span class="text-xs text-[#5A5150]">
                                            ({{ selectedAgent.skinMeta.name }} · {{ selectedAgent.deskPosition.label }})
                                        </span>
                                    </div>
                                    <p class="text-xs text-[#5A5150] truncate mt-0.5">
                                        <strong class="text-[#302B2A]">{{ selectedAgent.activePubTitle }}</strong> —
                                        <span class="text-[#245F59] font-semibold">{{ selectedAgent.statusText }}</span>
                                    </p>
                                </div>
                            </div>

                            <!-- Interactive Pose Preview Triggers (8s temporary preview) -->
                            <div class="flex items-center gap-1.5 overflow-x-auto w-full sm:w-auto pb-1 no-scrollbar shrink-0">
                                <span class="text-[10px] sm:text-[10.5px] font-semibold text-[#5A5150] mr-0.5 shrink-0">Uji Pose (8s):</span>
                                <button
                                    type="button"
                                    class="btn-secondary-pill whitespace-nowrap border border-[#D7D1C8] rounded-full px-2.5 py-1 text-[10.5px] sm:text-[11px] font-semibold cursor-pointer shrink-0"
                                    @click="commandAgentBehavior(selectedAgent.slot, 'working')"
                                >
                                    💻 Mengetik
                                </button>
                                <button
                                    type="button"
                                    class="btn-secondary-pill whitespace-nowrap border border-[#D7D1C8] rounded-full px-2.5 py-1 text-[10.5px] sm:text-[11px] font-semibold cursor-pointer shrink-0"
                                    @click="commandAgentBehavior(selectedAgent.slot, 'server')"
                                >
                                    🚶 Ke Server
                                </button>
                                <button
                                    type="button"
                                    class="btn-secondary-pill whitespace-nowrap border border-[#D7D1C8] rounded-full px-2.5 py-1 text-[10.5px] sm:text-[11px] font-semibold cursor-pointer shrink-0"
                                    @click="commandAgentBehavior(selectedAgent.slot, 'coffee')"
                                >
                                    ☕ Ke Kopi
                                </button>
                                <button
                                    type="button"
                                    class="btn-secondary-pill whitespace-nowrap border border-[#D7D1C8] rounded-full px-2.5 py-1 text-[10.5px] sm:text-[11px] font-semibold cursor-pointer shrink-0"
                                    @click="commandAgentBehavior(selectedAgent.slot, 'done')"
                                >
                                    👋 Selesai
                                </button>
                                <button
                                    type="button"
                                    class="btn-coral-action whitespace-nowrap text-[10.5px] sm:text-[11px] font-semibold rounded-full px-3 py-1 border border-[#E88D8D] cursor-pointer shrink-0"
                                    @click="openSkinPickerForSlot(selectedAgent.slot)"
                                >
                                    Ganti Karakter (1-8)
                                </button>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- ========================================================= -->
                <!-- 3. KOLOM KANAN: "Live Feed & Riwayat Aktivitas"           -->
                <!-- ========================================================= -->
                <aside
                    class="markas-side bg-[#FCF9EA] border border-[#D7D1C8] rounded-2xl sm:rounded-[20px] p-3 sm:p-3.5 flex-col gap-3"
                    :class="[activeMobileTab === 'logs' ? 'flex' : 'hidden lg:flex']"
                >
                    <div class="pb-2.5 border-b border-[#D7D1C8]">
                        <div class="flex items-center justify-between gap-2">
                            <h2 class="mk-panel-title font-['Outfit'] font-semibold text-[#302B2A] truncate">
                                Live Feed Aktivitas
                            </h2>
                            <span
                                class="font-['JetBrains_Mono'] tabular-nums text-[10.5px] font-semibold text-[#5A5150] bg-[#FFFFFF] border border-[#D7D1C8] rounded-full px-2 py-0.5 shrink-0"
                            >
                                {{ officeState.server_time || '09:05:12' }}
                            </span>
                        </div>
                        <p class="text-[11px] text-[#5A5150] mt-0.5">
                            {{
                                liveActivitiesCount > 0
                                    ? `${liveActivitiesCount} respons baru dalam 1 menit terakhir`
                                    : 'Menampilkan riwayat respons API terakhir'
                            }}
                        </p>

                        <!-- Filter Pills -->
                        <div class="flex flex-wrap items-center gap-1.5 mt-2.5">
                            <button
                                type="button"
                                class="whitespace-nowrap text-[11px] rounded-full px-3 py-1 font-semibold transition-colors cursor-pointer"
                                :class="
                                    activeFeedFilter === 'all'
                                        ? 'btn-cocoa-ink'
                                        : 'btn-secondary-pill border border-[#D7D1C8]'
                                "
                                @click="activeFeedFilter = 'all'"
                            >
                                Semua Riwayat
                            </button>
                            <button
                                type="button"
                                class="whitespace-nowrap text-[11px] rounded-full px-2.5 py-1 font-semibold transition-colors cursor-pointer"
                                :class="
                                    activeFeedFilter === 'live'
                                        ? 'btn-cocoa-ink'
                                        : 'btn-secondary-pill border border-[#D7D1C8]'
                                "
                                @click="activeFeedFilter = 'live'"
                            >
                                Sedang Berjalan ({{ liveActivitiesCount }})
                            </button>
                            <button
                                type="button"
                                class="whitespace-nowrap text-[11px] rounded-full px-2.5 py-1 font-semibold transition-colors cursor-pointer"
                                :class="
                                    activeFeedFilter === 'issue'
                                        ? 'btn-cocoa-ink'
                                        : 'btn-secondary-pill border border-[#D7D1C8]'
                                "
                                @click="activeFeedFilter = 'issue'"
                            >
                                Gagal / Cek
                            </button>
                        </div>
                    </div>

                    <!-- Empty State when filtering 'Sedang Berjalan' while no audit is running -->
                    <div
                        v-if="combinedActivities.length === 0"
                        class="bg-[#FFFFFF] border border-[#D7D1C8] rounded-xl p-5 text-center"
                    >
                        <p class="text-xs font-semibold text-[#302B2A]">
                            {{
                                activeFeedFilter === 'live'
                                    ? 'Tidak ada pemeriksaan yang sedang berjalan saat ini.'
                                    : 'Belum ada data log yang cocok dengan filter ini.'
                            }}
                        </p>
                        <p class="text-[11px] text-[#5A5150] mt-1">
                            Mulai pemeriksaan dokumen dari menu Publikasi untuk melihat aktivitas real-time.
                        </p>
                    </div>

                    <!-- Streaming / Historical Log Cards -->
                    <div v-else class="flex flex-col gap-2.5">
                        <div
                            v-for="item in combinedActivities"
                            :key="item.id"
                            class="bg-[#FFFFFF] border border-[#D7D1C8] hover:border-[#302B2A] rounded-xl p-3 transition-colors cursor-pointer"
                            @click="selectedSlot = item.agent_slot || 1"
                        >
                            <div class="flex items-center justify-between gap-2">
                                <div class="flex items-center gap-1.5 min-w-0">
                                    <span
                                        class="font-['JetBrains_Mono'] text-[10px] font-bold px-2 py-0.5 rounded-md shrink-0"
                                        :class="
                                            item.is_live
                                                ? 'bg-[#BADFDB] text-[#245F59]'
                                                : 'bg-[#FCF9EA] text-[#5A5150] border border-[#D7D1C8]'
                                        "
                                    >
                                        {{ item.is_live ? `Live #${item.agent_slot || 1}` : `Slot #${item.agent_slot || 1}` }}
                                    </span>
                                    <span class="text-xs font-bold text-[#302B2A] truncate">
                                        {{ item.agent_name }}
                                    </span>
                                </div>
                                <span
                                    class="font-['JetBrains_Mono'] tabular-nums text-[10px] text-[#5A5150] shrink-0"
                                    :title="item.created_at_time"
                                >
                                    {{ item.created_at_human || item.created_at_time }}
                                </span>
                            </div>

                            <p class="text-[11.5px] font-semibold text-[#302B2A] mt-1.5 truncate">
                                {{ item.publication_title }}
                            </p>
                            <p class="text-[10.5px] text-[#5A5150] mt-0.5 leading-snug">
                                {{ item.detail }}
                            </p>

                            <div class="mt-2 pt-1.5 border-t border-[#D7D1C8] flex items-center justify-between text-[10.5px]">
                                <span class="font-['JetBrains_Mono'] tabular-nums font-semibold text-[#302B2A]">
                                    Hal #{{ item.physical_page || 1 }}
                                </span>

                                <div class="flex items-center gap-1.5">
                                    <span
                                        v-if="item.duration_ms"
                                        class="font-['JetBrains_Mono'] tabular-nums text-[10px] text-[#5A5150]"
                                    >
                                        {{ (item.duration_ms / 1000).toFixed(1) }}s
                                    </span>
                                    <span
                                        class="px-2 py-0.5 rounded-full text-[9.5px] font-semibold"
                                        :class="
                                            item.status === 'issue' || item.status === 'error' || item.status === 'failed_validation'
                                                ? 'bg-[#FCF9EA] text-[#A5363F] border border-[#FFA4A4]'
                                                : item.status === 'done'
                                                  ? 'bg-[#FFBDBD] text-[#302B2A]'
                                                  : 'bg-[#BADFDB] text-[#245F59]'
                                        "
                                    >
                                        {{ item.status === 'verified_readable' ? 'readable' : item.status || 'readable' }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </aside>
            </div>

            <!-- ============================================================= -->
            <!-- MODAL PEMILIH KARAKTER / SKIN AGEN (agent-1.png s/d agent-8.png) -->
            <!-- ============================================================= -->
            <div
                v-if="showSkinModal"
                class="fixed inset-0 z-50 bg-[#302B2A]/40 backdrop-blur-[2px] flex items-center justify-center p-4"
                @click.self="showSkinModal = false"
            >
                <div class="bg-[#FCF9EA] border-2 border-[#302B2A] rounded-[24px] max-w-2xl w-full p-6 shadow-xl">
                    <div class="flex items-center justify-between gap-4 pb-4 border-b border-[#D7D1C8]">
                        <div>
                            <h3 class="mk-main-title font-['Outfit'] font-semibold text-[#302B2A]">
                                Pilih Karakter 3D — Agen Slot #{{ skinModalTargetSlot }}
                            </h3>
                            <p class="text-xs text-[#5A5150] mt-0.5">
                                Pilih dari 8 sprite karakter tersedia (`agent-1.png` s/d `agent-8.png`) untuk menempati meja Slot #{{
                                    skinModalTargetSlot
                                }}.
                            </p>
                        </div>
                        <button
                            type="button"
                            class="btn-secondary-pill border border-[#D7D1C8] rounded-full px-3.5 py-1.5 text-xs font-semibold cursor-pointer"
                            @click="showSkinModal = false"
                        >
                            Tutup
                        </button>
                    </div>

                    <div class="flex flex-wrap items-center gap-2 my-4">
                        <span class="text-xs font-semibold text-[#5A5150] mr-1">Target Slot:</span>
                        <button
                            v-for="s in 5"
                            :key="'tab-slot-' + s"
                            type="button"
                            class="text-xs font-semibold rounded-full px-3.5 py-1 border transition-colors cursor-pointer"
                            :class="
                                skinModalTargetSlot === s
                                    ? 'btn-cocoa-ink border-[#302B2A]'
                                    : 'btn-secondary-pill border-[#D7D1C8]'
                            "
                            @click="skinModalTargetSlot = s"
                        >
                            Slot #{{ s }}
                        </button>
                    </div>

                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                        <button
                            v-for="skin in SKIN_CATALOG"
                            :key="skin.id"
                            type="button"
                            class="bg-[#FFFFFF] border rounded-2xl p-3 flex flex-col items-center text-center transition-all cursor-pointer"
                            :class="
                                slotSkins[skinModalTargetSlot] === skin.id
                                    ? 'border-[#302B2A] ring-2 ring-[#FFA4A4] shadow-xs'
                                    : 'border-[#D7D1C8] hover:border-[#302B2A]'
                            "
                            @click="selectSkinForSlot(skin.id)"
                        >
                            <div
                                class="w-20 h-20 rounded-xl bg-[#FCF9EA] border border-[#D7D1C8] flex items-center justify-center overflow-hidden mb-2"
                            >
                                <div
                                    class="w-20 h-20"
                                    :style="{
                                        backgroundImage: `url('/assets/office/agent-${skin.id}.png')`,
                                        backgroundSize: '400% 200%',
                                        backgroundPosition: '0% 0%',
                                        backgroundRepeat: 'no-repeat',
                                    }"
                                ></div>
                            </div>
                            <span class="text-xs font-bold text-[#302B2A]">{{ skin.name }}</span>
                            <span class="text-[10.5px] text-[#5A5150] mt-0.5">{{ skin.role }}</span>
                            <span
                                class="mt-2 text-[10px] font-['JetBrains_Mono'] font-semibold px-2.5 py-0.5 rounded-full"
                                :class="
                                    slotSkins[skinModalTargetSlot] === skin.id
                                        ? 'bg-[#FFA4A4] text-[#302B2A]'
                                        : 'bg-[#FCF9EA] text-[#5A5150] border border-[#D7D1C8]'
                                "
                            >
                                {{ slotSkins[skinModalTargetSlot] === skin.id ? 'Terpilih' : `agent-${skin.id}.png` }}
                            </span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
.markas-grid {
    display: grid;
    grid-template-columns: minmax(0, 1fr);
    gap: 16px;
    align-items: start;
}

.markas-center {
    order: -1;
}

@media (min-width: 1024px) {
    .markas-grid {
        grid-template-columns: 275px minmax(0, 1fr) 275px;
        gap: 16px;
    }
    .markas-center {
        order: 0;
    }
    .markas-side {
        position: sticky;
        top: 84px;
        max-height: calc(100vh - 96px);
        overflow-y: auto;
        scrollbar-width: thin;
        scrollbar-color: #d7d1c8 transparent;
    }
}

@media (min-width: 1440px) {
    .markas-grid {
        grid-template-columns: 300px minmax(0, 1fr) 300px;
        gap: 20px;
    }
}

.office-diorama-stage {
    aspect-ratio: 3 / 2;
    container-type: inline-size;
    --sprite-size: clamp(78px, 14.2cqw, 215px);
    overflow: hidden;
    touch-action: none;
}

.office-camera-canvas {
    will-change: transform;
}

.office-camera-smooth {
    transition: transform 95ms cubic-bezier(0.22, 1, 0.36, 1);
}

.markas-agen-page p {
    margin: 0 !important;
    max-width: none !important;
}

.markas-agen-page .mk-main-title {
    font-size: 23px !important;
    line-height: 1.2 !important;
    margin: 0 !important;
}

.markas-agen-page .mk-panel-title {
    font-size: 17px !important;
    line-height: 1.25 !important;
    margin: 0 !important;
}

.markas-agen-page .mk-card-title {
    font-size: 13.5px !important;
    line-height: 1.25 !important;
    margin: 0 !important;
}

.btn-coral-action {
    background-color: #ffa4a4 !important;
    color: #302b2a !important;
}

.btn-coral-action:hover {
    background-color: #ff9191 !important;
    color: #302b2a !important;
}

.btn-cocoa-ink {
    background-color: #302b2a !important;
    color: #fcf9ea !important;
}

.btn-secondary-pill {
    background-color: #ffffff !important;
    color: #302b2a !important;
}

.btn-secondary-pill:hover {
    background-color: #fcf9ea !important;
    border-color: #302b2a !important;
    color: #302b2a !important;
}

@keyframes dashFlow {
    to {
        stroke-dashoffset: -4.8;
    }
}

.office-data-line {
    animation: dashFlow 1.8s linear infinite;
}

/* Office Zoom HUD Controls */
.office-hud-guide {
    font-size: 10px !important;
    line-height: 1 !important;
}

.office-hud-btn-step {
    width: 24px !important;
    height: 24px !important;
    font-size: 13px !important;
    line-height: 1 !important;
}

.office-hud-pct-badge {
    font-family: 'JetBrains Mono', monospace !important;
    font-size: 10px !important;
    line-height: 1 !important;
    padding: 3px 8px !important;
}

@media (min-width: 640px) {
    .office-hud-btn-step {
        width: 20px !important;
        height: 20px !important;
        font-size: 11px !important;
    }
}
</style>
