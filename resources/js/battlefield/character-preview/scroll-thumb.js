/**
 * Returns the size and position of a hand-drawn scrollbar thumb for a
 * scrolling box. The loadout roster draws its own thumb because Chrome can't
 * transition ::-webkit-scrollbar styles, so a native bar can only snap
 * between widths instead of easing wider on hover the way Firefox's does.
 *
 * @param {{scrollTop: number, scrollHeight: number, clientHeight: number,
 *     trackHeight: number, minSize?: number}} box
 * @return {{visible: boolean, size: number, offset: number}}
 */
export function thumbGeometry({ scrollTop, scrollHeight, clientHeight, trackHeight, minSize = 24 }) {
  const maxScroll = scrollHeight - clientHeight;
  if (maxScroll <= 0 || trackHeight <= 0) {
    return { visible: false, size: 0, offset: 0 };
  }
  const size = Math.min(trackHeight, Math.max(minSize, (clientHeight / scrollHeight) * trackHeight));
  const progress = Math.min(1, Math.max(0, scrollTop / maxScroll));
  return { visible: true, size, offset: progress * (trackHeight - size) };
}

/**
 * Returns the scrollTop that puts the thumb at `offset` — the inverse of
 * thumbGeometry, used while dragging the thumb or clicking the rail.
 *
 * @param {{offset: number, scrollHeight: number, clientHeight: number,
 *     trackHeight: number, minSize?: number}} box
 * @return {number}
 */
export function scrollTopForThumb({ offset, scrollHeight, clientHeight, trackHeight, minSize = 24 }) {
  const { visible, size } = thumbGeometry({ scrollTop: 0, scrollHeight, clientHeight, trackHeight, minSize });
  const travel = trackHeight - size;
  if (!visible || travel <= 0) {
    return 0;
  }
  const progress = Math.min(1, Math.max(0, offset / travel));
  return progress * (scrollHeight - clientHeight);
}
