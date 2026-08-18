# Crucible DB Design Direction

Crucible DB uses a quiet engineering-utility register for governed database access, query approval, scheduled execution, and audit review.

The normative visual system and component rules live in [`DESIGN.md`](../../DESIGN.md). The strategic product principles live in [`PRODUCT.md`](../../PRODUCT.md).

The Phase 1B direction uses patterns common to focused developer tools:

- Cool neutral surfaces keep attention on database work.
- Crucible orange identifies the product; blue identifies interactive actions and focus.
- Green, amber, red, and blue are reserved for operational state.
- Navigation follows Work, Data, and Admin.
- Native system typography keeps the interface familiar and avoids a styled dashboard feel.
- Data begins close to compact page headers and remains denser than form workflows.
- Tables, toolbars, code surfaces, and lifecycle state take priority over decorative cards.
- Query requests read like reviewable engineering changes: summary, SQL, decision, execution, and history.
- Shadows are reserved for overlays. Persistent work surfaces stay flat.
- Motion communicates state and respects reduced-motion preferences.

The interface must not resemble a generic CRUD admin template, decorative SaaS dashboard, Cloudflare clone, or toy SQL editor. Decorative eyebrows, icon tiles, trust claims, nested cards, and invented marketing language are avoided. Approval state, execution state, risk, and the next action remain visible beside the object they affect.
