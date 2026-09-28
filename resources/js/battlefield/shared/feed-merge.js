/**
 * Groups the battlefield feed so a burst of events stays readable: same actor
 * and kind within `sameMs` → ×N; other actors within `crowdMs` → "a, b +N".
 *
 * @param {{max?:number, sameMs?:number, crowdMs?:number, lifeMs?:number, historyMax?:number}} [opts]
 * @return {{push:function(string,string,number):object, visible:function(number):{lines:object[],earlier:number}, history:function():object[]}}
 */
export function createFeed({ max = 3, sameMs = 8000, crowdMs = 4000, lifeMs = 7000, historyMax = 12 } = {}) {
  let lines = [];
  let seq = 0;
  const hist = [];
  /**
   * The subset of `lines` that haven't aged out as of `now`.
   * @param {number} now
   * @return {object[]}
   */
  const alive = now => lines.filter(l => now - l.at <= lifeMs);
  return {
    /**
     * Records one raw event and folds it into the matching visible line, if any.
     * @param {string} kind
     * @param {string} actor
     * @param {number} now
     * @return {{id:number, kind:string, actors:string[], count:number, at:number}} the line this event joined or started
     */
    push(kind, actor, now) {
      hist.unshift({ kind, actor, at: now });
      hist.length = Math.min(hist.length, historyMax);
      lines = alive(now);
      const last = lines.findLast(l => l.kind === kind);
      const same = last && last.actors.at(-1) === actor && now - last.at <= sameMs;
      const crowd = last && now - last.at <= crowdMs;
      if (same || crowd) {
        if (!last.actors.includes(actor)) { last.actors.push(actor); }
        last.count++;
        last.at = now;
        return last;
      }
      const line = { id: ++seq, kind, actors: [actor], count: 1, at: now };
      lines.push(line);
      return line;
    },
    /**
     * The lines still alive at `now`, capped to `max`, with the rest counted.
     * @param {number} now
     * @return {{lines:object[], earlier:number}}
     */
    visible(now) {
      const live = alive(now);
      return { lines: live.slice(-max), earlier: Math.max(0, live.length - max) };
    },
    /**
     * The raw event log, newest first, capped to `historyMax`.
     * @return {{kind:string, actor:string, at:number}[]}
     */
    history() {
      return hist.slice();
    },
  };
}
