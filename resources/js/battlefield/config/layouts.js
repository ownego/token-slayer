export const LAYOUTS = {
  landscape: {
    logicalWidth: 960,
    logicalHeight: 540,
    boss: { anchor: { x: 480, y: 180 }, scale: 4, name: { x: 480, y: 100 } },
    hpBar: { x: 480, y: 300, width: 200, height: 12 },
    fighters: { rowXRange: [80, 880], rowY: 460, perRowMax: 14 },
    bats: { centerX: 480, centerY: 140, radiusX: 220, radiusY: 45, hopRadius: 70 },
    necromancer: { anchor: { x: 90, y: 320 }, wanderRadiusX: 30, wanderRadiusY: 20 },
  },
  portrait: {
    logicalWidth: 540,
    logicalHeight: 960,
    boss: { anchor: { x: 270, y: 310 }, scale: 5, name: { x: 270, y: 200 } },
    hpBar: { x: 270, y: 430, width: 280, height: 12 },
    fighters: { rowXRange: [50, 490], rowY: 820, perRowMax: 10 },
    bats: { centerX: 270, centerY: 255, radiusX: 140, radiusY: 40, hopRadius: 55 },
    necromancer: { anchor: { x: 70, y: 480 }, wanderRadiusX: 25, wanderRadiusY: 20 },
  },
};
