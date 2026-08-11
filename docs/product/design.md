# Crucible DB Design Direction

Crucible DB uses a product UI register: an operational console for database access, query approvals, scheduled execution, and zero-trust audit review.

Visual direction:
- Follow the Cloudflare/Kumo product register: white or near-white content surfaces, neutral recessed sidebar, subtle grey borders, orange brand signal, and blue only for primary action or link emphasis.
- Use semantic color only for state: amber for review/waiting, green for completed/active, red for failure/rejection, blue for running/scheduled, and neutral ink for default content.
- Prefer structured surfaces, tables, code blocks, badges, and toolbars over decorative cards or marketing layouts.
- Sidebar navigation follows Cloudflare/Kumo layout: the sidebar uses the same neutral canvas as the main area, top and footer bands have horizontal separators, active items are quiet neutral blocks, and menu icons sit with generous left inset.
- Main content uses Cloudflare-like desktop gutters: the first content column should sit well away from the sidebar border, with the top header/breadcrumb gutter aligned to the page body.
- Keep cards at small radii, avoid nested cards, and make repeated objects easy to scan.
- Use monospaced styling for SQL, endpoint names, action identifiers, IP addresses, and metadata.
- Keep motion subtle and tied to state changes: hover, focus, active, loading.

Interaction direction:
- Primary actions use icon plus label where the command benefits from recognition.
- Statuses and access modes use consistent pill vocabulary across every screen.
- Forms use explicit submit actions and grouped fields. Secrets and transport settings should feel distinct from naming/identity fields.
- Empty states should be compact and actionable, not blank filler.

The interface should feel like a serious internal control plane: quiet, durable, fast to scan, and visibly safer than direct database access.
