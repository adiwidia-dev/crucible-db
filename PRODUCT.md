# Product

## Users

Crucible DB serves developers, reviewers, SREs, DBAs, security operators, and administrators. They use it during routine engineering work and high-attention deployment windows to request, review, schedule, execute, and audit database operations without distributing production credentials.

## Product Purpose

Crucible DB is a governed database operations control plane. It makes every database action visible, reviewable, time-bounded when appropriate, and attributable. The current product supports deployment batches of ordered, single SQL statements and query-access sessions with a declared read-only or read + write level. Administrators organise targets into explicit connection groups, apply role defaults to those groups, and use individual connection exceptions only when needed. Success means a user can understand target scope, risk, approval state, next action, execution result, and audit history without leaving the workflow or guessing whether an operation ran.

## Brand Personality

Precise, calm, and trustworthy. The product should feel like a durable engineering workbench: technically capable, direct, and composed under pressure.

## Anti-references

Do not resemble a generic CRUD admin template, a decorative SaaS dashboard, a Cloudflare clone, or a toy SQL editor. Avoid oversized marketing headers, vanity metric cards, nested card layouts, decorative gradients, glass effects, hidden approval state, and interactions that separate an object from its next action.

## Design Principles

- **Operations before objects.** Organize the product around requesting, reviewing, executing, and auditing work.
- **State is part of the object.** Approval, risk, schedule, execution, and access state remain visible wherever the object appears.
- **Proximity creates confidence.** Put the next action, its consequences, and its feedback beside the object it affects.
- **Dense, never crowded.** Optimize repeated desktop workflows with compact structure, strong hierarchy, and progressive disclosure.
- **Friction follows risk.** Routine navigation stays fast; destructive or production-impacting actions receive explicit context and confirmation.
- **Access is explicit.** A query-access session declares its permitted level before it starts, and the application enforces that level for every query.
- **Warnings guide; blocks protect.** Conservative preflight warnings inform requesters and reviewers, while definite safety failures prevent approval or execution.
- **Policy is deliberate.** Workspace administrators decide which governed SQL statement families are available; unrecognised, administrative, file, and security-management SQL stays unavailable.

## Accessibility & Inclusion

Meet WCAG 2.2 AA. All workflows must be keyboard accessible, preserve visible focus, support reduced motion, and communicate state with text or iconography in addition to color. Data tables, forms, and code surfaces must remain usable at 200 percent zoom and on narrow viewports.
