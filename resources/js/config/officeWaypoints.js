/**
 * Isometric Office Floor Waypoint Graph, 5 Workstation Geometry,
 * Keyboard/Hand Alignment Calibration, & Pathfinding System
 * Coordinates are normalized percentages (top %, left %) on the 3:2 office-bg.png canvas.
 */

export const WORKSTATION_CONFIG = Object.freeze({
    1: {
        slot: 1,
        label: 'Meja Kiri Atas',
        facing: 'left',
        direction: 'rear-left',
        typingSprite: '/assets/office/typing/agent-1-typing.png?v=20261022_v10',
        seatPosition: { top: 31.4, left: 38.7, facing: 'left' },
        keyboardPosition: { top: 33.4, left: 36.9 },
        approachPosition: { top: 39.2, left: 41.6, facing: 'left' },
        exitPosition: { top: 42.5, left: 43.2, facing: 'right' },
        chairClipInset: '28% 53% 51% 34%',
    },
    2: {
        slot: 2,
        label: 'Meja Kanan Atas',
        facing: 'right',
        direction: 'rear-right',
        typingSprite: '/assets/office/typing/agent-2-typing.png?v=20261022_v10',
        seatPosition: { top: 28.6, left: 57.6, facing: 'right' },
        keyboardPosition: { top: 30.6, left: 60.0 },
        approachPosition: { top: 37.2, left: 55.8, facing: 'right' },
        exitPosition: { top: 41.8, left: 54.5, facing: 'left' },
        chairClipInset: '26% 36% 53% 51%',
    },
    3: {
        slot: 3,
        label: 'Meja Kiri Bawah',
        facing: 'left',
        direction: 'rear-left',
        typingSprite: '/assets/office/typing/agent-3-typing.png?v=20261022_v10',
        seatPosition: { top: 55.3, left: 28.6, facing: 'left' },
        keyboardPosition: { top: 56.8, left: 26.8 },
        approachPosition: { top: 55.4, left: 34.2, facing: 'left' },
        exitPosition: { top: 53.5, left: 37.2, facing: 'right' },
        chairClipInset: '51% 63% 28% 23%',
    },
    4: {
        slot: 4,
        label: 'Meja Tengah Bawah',
        facing: 'left',
        direction: 'back-three-quarter',
        typingSprite: '/assets/office/typing/agent-4-typing.png?v=20261022_v10',
        seatPosition: { top: 70.2, left: 51.2, facing: 'left' },
        keyboardPosition: { top: 71.6, left: 48.8 },
        approachPosition: { top: 66.2, left: 49.8, facing: 'left' },
        exitPosition: { top: 61.8, left: 49.0, facing: 'right' },
        chairClipInset: '66% 41% 13% 45%',
    },
    5: {
        slot: 5,
        label: 'Meja Kanan Bawah',
        facing: 'left',
        direction: 'rear-left',
        typingSprite: '/assets/office/typing/agent-5-typing.png?v=20261022_v10',
        seatPosition: { top: 55.4, left: 73.9, facing: 'left' },
        keyboardPosition: { top: 57.4, left: 72.0 },
        approachPosition: { top: 55.8, left: 68.4, facing: 'left' },
        exitPosition: { top: 54.2, left: 63.8, facing: 'left' },
        chairClipInset: '52% 18% 27% 68%',
    },
});

/**
 * Unhydrated template with `null` placeholders before calibration hydration (as specified in Section 4)
 */
export function createNullTypingCalibration() {
    return {
        1: { seatX: null, seatY: null, scale: 1, offsetX: 0, offsetY: 0, keyboardX: null, keyboardY: null },
        2: { seatX: null, seatY: null, scale: 1, offsetX: 0, offsetY: 0, keyboardX: null, keyboardY: null },
        3: { seatX: null, seatY: null, scale: 1, offsetX: 0, offsetY: 0, keyboardX: null, keyboardY: null },
        4: { seatX: null, seatY: null, scale: 1, offsetX: 0, offsetY: 0, keyboardX: null, keyboardY: null },
        5: { seatX: null, seatY: null, scale: 1, offsetX: 0, offsetY: 0, keyboardX: null, keyboardY: null },
    };
}

/**
 * Pixel-verified default typing calibration for all 5 workstations on the 1536x1024 office-bg.png
 */
export const DEFAULT_TYPING_CALIBRATION = Object.freeze({
    1: {
        seatX: 38.7,
        seatY: 31.4,
        scale: 1.0,
        offsetX: 0,
        offsetY: 0,
        keyboardX: 36.9,
        keyboardY: 33.4,
        fps: 10.0,
        direction: 'rear-left',
        pivotX: 50,
        pivotY: 52,
        lockedFrame: 0, // 0 = auto loop 1..8, 1..8 = lock specific frame
        insetBottomPct: 0,
    },
    2: {
        seatX: 57.6,
        seatY: 28.6,
        scale: 1.0,
        offsetX: 0,
        offsetY: 0,
        keyboardX: 60.0,
        keyboardY: 30.6,
        fps: 10.0,
        direction: 'rear-right',
        pivotX: 50,
        pivotY: 52,
        lockedFrame: 0,
        insetBottomPct: 0,
    },
    3: {
        seatX: 28.6,
        seatY: 55.3,
        scale: 1.0,
        offsetX: 0,
        offsetY: 0,
        keyboardX: 26.8,
        keyboardY: 56.8,
        fps: 10.0,
        direction: 'rear-left',
        pivotX: 50,
        pivotY: 52,
        lockedFrame: 0,
        insetBottomPct: 0,
    },
    4: {
        seatX: 51.2,
        seatY: 70.2,
        scale: 1.0,
        offsetX: 0,
        offsetY: 0,
        keyboardX: 48.8,
        keyboardY: 71.6,
        fps: 9.2,
        direction: 'back-three-quarter',
        pivotX: 50,
        pivotY: 52,
        lockedFrame: 0,
        insetBottomPct: 0,
    },
    5: {
        seatX: 73.9,
        seatY: 55.4,
        scale: 1.0,
        offsetX: 0,
        offsetY: 0,
        keyboardX: 72.0,
        keyboardY: 57.4,
        fps: 10.0,
        direction: 'rear-left',
        pivotX: 50,
        pivotY: 52,
        lockedFrame: 0,
        insetBottomPct: 0,
    },
});

export const WAYPOINT_NODES = Object.freeze({
    // Chair Approach & Exit Nodes for each of the 5 workstations
    approach_1: { id: 'approach_1', top: 39.2, left: 41.6, facing: 'left', label: 'Approach Meja 1' },
    approach_2: { id: 'approach_2', top: 37.2, left: 55.8, facing: 'right', label: 'Approach Meja 2' },
    approach_3: { id: 'approach_3', top: 55.4, left: 34.2, facing: 'left', label: 'Approach Meja 3' },
    approach_4: { id: 'approach_4', top: 66.2, left: 49.8, facing: 'left', label: 'Approach Meja 4' },
    approach_5: { id: 'approach_5', top: 55.8, left: 68.4, facing: 'left', label: 'Approach Meja 5' },

    exit_1: { id: 'exit_1', top: 42.5, left: 43.2, label: 'Exit Meja 1' },
    exit_2: { id: 'exit_2', top: 41.8, left: 54.5, label: 'Exit Meja 2' },
    exit_3: { id: 'exit_3', top: 53.5, left: 37.2, label: 'Exit Meja 3' },
    exit_4: { id: 'exit_4', top: 61.8, left: 49.0, label: 'Exit Meja 4' },
    exit_5: { id: 'exit_5', top: 54.2, left: 63.8, label: 'Exit Meja 5' },

    // Central Walkway Ring (routes cleanly around the Central Server at 49.5%, 48.4%)
    ring_nw: { id: 'ring_nw', top: 43.5, left: 43.8, label: 'Koridor Barat Laut' },
    ring_n:  { id: 'ring_n',  top: 41.8, left: 48.6, label: 'Koridor Utara' },
    ring_ne: { id: 'ring_ne', top: 43.2, left: 55.2, label: 'Koridor Timur Laut' },
    ring_e:  { id: 'ring_e',  top: 49.0, left: 59.5, label: 'Koridor Timur' },
    ring_se: { id: 'ring_se', top: 58.0, left: 56.8, label: 'Koridor Tenggara' },
    ring_s:  { id: 'ring_s',  top: 60.2, left: 49.0, label: 'Koridor Selatan' },
    ring_sw: { id: 'ring_sw', top: 57.8, left: 41.5, label: 'Koridor Barat Daya' },
    ring_w:  { id: 'ring_w',  top: 49.5, left: 39.2, label: 'Koridor Barat' },

    // Coffee Lounge Entry & Individual Agent Spots (near water dispenser / coffee corner)
    coffee_entry: { id: 'coffee_entry', top: 43.2, left: 64.5, label: 'Koridor Kopi' },
    coffee_1: { id: 'coffee_1', top: 40.0, left: 68.5, facing: 'right', label: 'Spot Kopi #1' },
    coffee_2: { id: 'coffee_2', top: 37.8, left: 71.2, facing: 'right', label: 'Spot Kopi #2' },
    coffee_3: { id: 'coffee_3', top: 41.5, left: 71.8, facing: 'left',  label: 'Spot Kopi #3' },
    coffee_4: { id: 'coffee_4', top: 39.0, left: 74.6, facing: 'left',  label: 'Spot Kopi #4' },
    coffee_5: { id: 'coffee_5', top: 42.6, left: 74.8, facing: 'left',  label: 'Spot Kopi #5' },

    // Server Terminal Spots (standing around the Central Server without overlapping it)
    server_1: { id: 'server_1', top: 46.5, left: 42.5, facing: 'right', label: 'Terminal Server #1' },
    server_2: { id: 'server_2', top: 46.5, left: 54.8, facing: 'left',  label: 'Terminal Server #2' },
    server_3: { id: 'server_3', top: 53.2, left: 42.2, facing: 'right', label: 'Terminal Server #3' },
    server_4: { id: 'server_4', top: 56.2, left: 48.8, facing: 'right', label: 'Terminal Server #4' },
    server_5: { id: 'server_5', top: 53.2, left: 55.2, facing: 'left',  label: 'Terminal Server #5' },
});

// Bidirectional floor edges connecting walkable corridor nodes
export const WAYPOINT_EDGES = Object.freeze([
    // Approach <-> Exit chair transitions
    ['approach_1', 'exit_1'],
    ['approach_2', 'exit_2'],
    ['approach_3', 'exit_3'],
    ['approach_4', 'exit_4'],
    ['approach_5', 'exit_5'],

    // Ring perimeter around central server
    ['ring_nw', 'ring_n'],
    ['ring_n', 'ring_ne'],
    ['ring_ne', 'ring_e'],
    ['ring_e', 'ring_se'],
    ['ring_se', 'ring_s'],
    ['ring_s', 'ring_sw'],
    ['ring_sw', 'ring_w'],
    ['ring_w', 'ring_nw'],

    // Chair exits into walkway ring
    ['exit_1', 'ring_nw'],
    ['exit_1', 'ring_n'],
    ['exit_2', 'ring_ne'],
    ['exit_2', 'ring_n'],
    ['exit_3', 'ring_w'],
    ['exit_3', 'ring_sw'],
    ['exit_4', 'ring_s'],
    ['exit_4', 'ring_sw'],
    ['exit_4', 'ring_se'],
    ['exit_5', 'ring_e'],
    ['exit_5', 'ring_se'],
    ['exit_5', 'coffee_entry'],

    // Coffee corridor connections
    ['ring_ne', 'coffee_entry'],
    ['ring_e', 'coffee_entry'],
    ['coffee_entry', 'coffee_1'],
    ['coffee_entry', 'coffee_2'],
    ['coffee_entry', 'coffee_3'],
    ['coffee_1', 'coffee_2'],
    ['coffee_2', 'coffee_4'],
    ['coffee_3', 'coffee_4'],
    ['coffee_3', 'coffee_5'],

    // Server interaction terminals connected to ring
    ['ring_nw', 'server_1'],
    ['ring_w', 'server_1'],
    ['ring_ne', 'server_2'],
    ['ring_e', 'server_2'],
    ['ring_w', 'server_3'],
    ['ring_sw', 'server_3'],
    ['ring_s', 'server_4'],
    ['ring_sw', 'server_4'],
    ['ring_se', 'server_4'],
    ['ring_e', 'server_5'],
    ['ring_se', 'server_5'],
]);

// Build adjacency map with Euclidean edge weights
const ADJACENCY = (() => {
    const map = {};
    for (const key of Object.keys(WAYPOINT_NODES)) {
        map[key] = [];
    }
    for (const [a, b] of WAYPOINT_EDGES) {
        const na = WAYPOINT_NODES[a];
        const nb = WAYPOINT_NODES[b];
        if (!na || !nb) continue;
        const dist = Math.hypot(nb.left - na.left, nb.top - na.top);
        map[a].push({ id: b, cost: dist });
        map[b].push({ id: a, cost: dist });
    }
    return map;
})();

function distanceBetween(p1, p2) {
    return Math.hypot(p2.left - p1.left, p2.top - p1.top);
}

/**
 * Find shortest path of node IDs between startNodeId and endNodeId using Dijkstra
 */
export function findNodePath(startNodeId, endNodeId) {
    if (!WAYPOINT_NODES[startNodeId] || !WAYPOINT_NODES[endNodeId]) return [];
    if (startNodeId === endNodeId) return [startNodeId];

    const dist = {};
    const prev = {};
    const visited = new Set();
    const queue = [];

    for (const key of Object.keys(WAYPOINT_NODES)) {
        dist[key] = Infinity;
    }
    dist[startNodeId] = 0;
    queue.push({ id: startNodeId, cost: 0 });

    while (queue.length > 0) {
        queue.sort((a, b) => a.cost - b.cost);
        const current = queue.shift();
        if (visited.has(current.id)) continue;
        visited.add(current.id);

        if (current.id === endNodeId) break;

        for (const neighbor of ADJACENCY[current.id] || []) {
            if (visited.has(neighbor.id)) continue;
            const alt = dist[current.id] + neighbor.cost;
            if (alt < dist[neighbor.id]) {
                dist[neighbor.id] = alt;
                prev[neighbor.id] = current.id;
                queue.push({ id: neighbor.id, cost: alt });
            }
        }
    }

    const path = [];
    let curr = endNodeId;
    while (curr) {
        path.unshift(curr);
        if (curr === startNodeId) break;
        curr = prev[curr];
    }

    return path[0] === startNodeId ? path : [endNodeId];
}

/**
 * Find the nearest walkable corridor node to an arbitrary (top, left) position
 */
export function findNearestCorridorNode(pos, slotNumber = 1, seatPosition = null) {
    if (seatPosition && distanceBetween(pos, seatPosition) < 4.5) {
        return `approach_${slotNumber}`;
    }

    let bestId = `exit_${slotNumber}`;
    let bestDist = Infinity;

    for (const [id, node] of Object.entries(WAYPOINT_NODES)) {
        const d = distanceBetween(pos, node);
        if (d < bestDist) {
            bestDist = d;
            bestId = id;
        }
    }
    return bestId;
}

/**
 * Build a complete ordered array of waypoint coordinates [{ top, left, id, facing, role }]
 * from the agent's current position to the desired target zone ('desk' | 'coffee' | 'server').
 */
export function buildOfficeRoute({
    currentPos,
    targetZone,
    slotNumber = 1,
    deskPosition,
}) {
    const slot = Math.min(5, Math.max(1, Number(slotNumber) || 1));
    const ws = WORKSTATION_CONFIG[slot] || WORKSTATION_CONFIG[1];

    const finalSeat = {
        id: `seat_${slot}`,
        role: 'seat',
        top: Number(deskPosition?.top ?? ws.seatPosition.top),
        left: Number(deskPosition?.left ?? ws.seatPosition.left),
        facing: deskPosition?.facing || ws.facing,
    };

    let destNodeId = `approach_${slot}`;
    let finalPoint = finalSeat;

    if (targetZone === 'coffee') {
        destNodeId = `coffee_${slot}`;
        const cNode = WAYPOINT_NODES[destNodeId] || WAYPOINT_NODES.coffee_1;
        finalPoint = {
            id: destNodeId,
            role: 'coffee',
            top: cNode.top,
            left: cNode.left,
            facing: cNode.facing || 'left',
        };
    } else if (targetZone === 'server') {
        destNodeId = `server_${slot}`;
        const sNode = WAYPOINT_NODES[destNodeId] || WAYPOINT_NODES.server_1;
        finalPoint = {
            id: destNodeId,
            role: 'server',
            top: sNode.top,
            left: sNode.left,
            facing: sNode.facing || 'right',
        };
    } else {
        destNodeId = `approach_${slot}`;
        finalPoint = finalSeat;
    }

    if (distanceBetween(currentPos, finalPoint) <= 0.6) {
        return [];
    }

    const startNodeId = findNearestCorridorNode(currentPos, slot, finalSeat);
    const nodeIds = findNodePath(startNodeId, destNodeId);

    const waypoints = [];
    for (const id of nodeIds) {
        const n = WAYPOINT_NODES[id];
        if (!n) continue;
        if (waypoints.length === 0 && distanceBetween(currentPos, n) < 0.9) {
            continue;
        }
        waypoints.push({
            id: n.id,
            role: id.startsWith('approach_') ? 'approach' : id.startsWith('exit_') ? 'exit' : 'corridor',
            top: n.top,
            left: n.left,
            facing: n.facing || null,
        });
    }

    const lastWp = waypoints[waypoints.length - 1];
    if (!lastWp || distanceBetween(lastWp, finalPoint) > 0.5) {
        waypoints.push(finalPoint);
    }

    return waypoints;
}
