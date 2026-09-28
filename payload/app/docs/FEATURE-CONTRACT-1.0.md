# FFXI Gear Center 1.0 – Feature Contract

This file is the regression contract for the final consolidated application. "Cleanup" may remove duplicate code paths, old launchers and technical staging UI, but must not remove the user-facing capability listed here.

## 0.1 / 0.2 foundations
- GearFinder/SQLite inventory import remains available as the historical/catalog layer.
- LuAshitacast NIN/THF/COMMON sets remain readable, with Owned/Required matching.
- Physical live inventory is the authoritative current-state layer.

## 0.3 item knowledge and actionable planning
- Unified logical Item Catalog remains available for owned, missing, optional and reference-only items.
- Every item shown in Inventory, Gearplanung, Farmplan or performance results can be opened.
- Item detail retains cached BG-Wiki canonical page/title/link, structured acquisition/progression records and cleaned fallback excerpt.
- Item detail retains acquisition routes, progression/upgrades, materials/currencies, LAC usage, related goals and To-Do creation.
- Explicit acquisition/progression relationships are never guessed from similar names.
- To-Dos and step state remain accessible contextually from Farmplan/item detail.
- Snapshot changes remain accessible from Datenstatus.
- Unknown quantities remain unknown, never zero.

## 0.4 global guide/target knowledge
- 22-job structured guide targets remain the recommendation catalog.
- Job -> Set -> Variant structure and target requirements survive consolidation.
- Guide recommendations never automatically become personal goals.
- Cached BG-Wiki target/item/progression data is preserved and connected to target/farm items.
- Identity/route/augment/freshness/chain verification remains available under technical diagnostics, not in the normal user flow.

## 0.5 personal planning UX
- A complete set variant can explicitly be selected as a personal goal.
- All variants of one set can be selected together.
- Personal goals persist and can be removed again.
- Gearplanning is searchable by job, set, variant and item.
- Personal-goal filtering is available.

## 0.6 live character state
- GearplanerBridge is authoritative for all 17 physical containers, ordinary items, equipped mapping and currencies it captures.
- Points remains authoritative for its overlapping progression/event values via the read-only export hook.
- Physical duplicate items stay distinct by container/slot/extra data.
- Augments are mapped one-to-one to physical copies and preserve augment strings, Path, Rank and Trial when present.
- The last valid character/inventory state remains usable when FFXI is not running; login/live capture updates it, while zero-item/pre-login/partial frames never erase a valid last-known-good state.

## 0.7 performance
- Existing Gear Lab / Kastra is consumed read-only.
- Performance priorities are shown only when explicit modeled/measured gain evidence exists.
- Performance items link back into the same item-detail knowledge layer.
- Retired Combat Capture is not part of Gear Center runtime/UI.

## 0.8 farm/dependency integration
- Farmplan contains only explicitly selected personal goals.
- Reusable shared gear is aggregated by max-across-goals, not summed per goal.
- Consumables/currencies remain additive.
- Each farm item links to BG-Wiki acquisition/progression/material details and, when cached, shows a concise acquisition hint directly in the Farmplan.
- The goals depending on each resource are directly openable.

## 1.0 lifecycle and UX
- Exactly one normal top-level navigation: Übersicht, Inventar, Gearplanung, Farmplan, Gear-Vergleich, Charakter, Datenstatus.
- Item/goal/task/set details are contextual drill-downs, not a second competing main menu.
- FFXI Center is the only normal lifecycle owner: opening the Center acquires/starts API+web; the last Center owner closes exactly the processes started by that lifecycle.
- Closing one of several Center windows must not stop services still owned by another Center window.
- A pre-existing unrelated service is never adopted/killed.
- Direct normal service start is disabled; helper scripts are internal/diagnostic only.
- Automatic refresh preserves inventory search text, focus and caret; augment-only changes invalidate the dashboard revision.
- SQLite-backed item-detail resource holdings track the latest complete local live snapshot; older or less-complete frames preserve the newer/complete last-known-good holdings.

## UX navigation contract: no dead ends
- Every summary card on Übersicht leads to the corresponding working area.
- Every inventory, planning, farming and performance item opens the same Item detail knowledge layer.
- Item detail leads onward to LAC usage, related goals, To-Dos, acquisition routes, progression chains, materials/currencies and cached/original BG-Wiki evidence.
- Every goal reference in Übersicht, Farmplan, Performance and currency relevance can reopen the matching Job -> Set -> Variant.
- Waiting/empty states provide the next useful action (Gearplanung or Datenstatus) instead of a terminal message.
- Known key-item names link to BG-Wiki lookup; unknown names remain explicit IDs and are never guessed.
- An unresolved item lookup still offers BG-Wiki search, catalog search and an explicit user-goal action.
- Contextual detail pages always provide a route back to the originating main area.

