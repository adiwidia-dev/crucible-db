---
name: Crucible DB
description: A quiet engineering utility for governed database access.
colors:
    brand: 'oklch(0.59 0.18 43)'
    action: 'oklch(0.53 0.17 258)'
    action-hover: 'oklch(0.47 0.16 258)'
    canvas: 'oklch(0.985 0.002 247)'
    surface: 'oklch(1 0 0)'
    ink: 'oklch(0.21 0.012 255)'
    muted-surface: 'oklch(0.965 0.004 247)'
    muted-ink: 'oklch(0.48 0.016 255)'
    rule: 'oklch(0.9 0.008 247)'
    success: 'oklch(0.52 0.12 158)'
    warning: 'oklch(0.58 0.14 72)'
    danger: 'oklch(0.55 0.19 27)'
typography:
    headline:
        fontFamily: 'ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, sans-serif'
        fontSize: '1.5rem'
        fontWeight: 600
        lineHeight: 1.15
        letterSpacing: '-0.02em'
    title:
        fontFamily: 'ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, sans-serif'
        fontSize: '1rem'
        fontWeight: 600
        lineHeight: 1.35
    body:
        fontFamily: 'ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, sans-serif'
        fontSize: '0.875rem'
        fontWeight: 400
        lineHeight: 1.5
    label:
        fontFamily: 'ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, sans-serif'
        fontSize: '0.75rem'
        fontWeight: 600
        lineHeight: 1.3
        letterSpacing: '0.04em'
rounded:
    sm: '4px'
    md: '6px'
    lg: '8px'
spacing:
    xs: '4px'
    sm: '8px'
    md: '16px'
    lg: '24px'
    xl: '32px'
components:
    button-primary:
        backgroundColor: '{colors.action}'
        textColor: '{colors.surface}'
        rounded: '{rounded.md}'
        height: '36px'
        padding: '0 14px'
    button-primary-hover:
        backgroundColor: '{colors.action-hover}'
        textColor: '{colors.surface}'
    input:
        backgroundColor: '{colors.surface}'
        textColor: '{colors.ink}'
        rounded: '{rounded.md}'
        height: '36px'
        padding: '0 12px'
    navigation-active:
        backgroundColor: '{colors.muted-surface}'
        textColor: '{colors.ink}'
        rounded: '{rounded.md}'
---

# Design System: Crucible DB

## Overview

**Creative North Star: "The Quiet Engineering Utility"**

Crucible DB should feel like infrastructure software made by and for engineers: direct, compact, and dependable. Neutral surfaces carry the work, blue identifies interaction, and orange is limited to the Crucible brand. Personality comes from clear information hierarchy and confident restraint rather than decorative styling.

The system rejects generic CRUD admin templates, decorative SaaS dashboards, Cloudflare imitation, toy SQL editors, oversized marketing headers, and nested cards. Data begins close to the page header, workflows remain spatially stable, and every surface makes its current state and next action clear.

**Key Characteristics:**

- Cool neutral canvas with white work surfaces
- Compact desktop density with responsive structural changes
- Orange reserved for product identity; blue reserved for interaction
- Monospaced data for SQL, endpoints, identifiers, and timestamps
- Flat-by-default layering with borders and spacing instead of shadow

## Colors

The palette combines cool neutrals with separate brand and action colors. Semantic colors are functional vocabulary, never decoration.

### Primary

- **Action Blue:** Primary actions, links, keyboard focus, and selected controls.
- **Crucible Orange:** Logo and sparse brand identity only.

### Secondary

- **Execution Blue:** Running, scheduled, linked, and informational states only.

### Tertiary

- **Verified Green:** Completed, active, and healthy states.
- **Review Amber:** Waiting, review, and warning states.
- **Failure Red:** Rejected, failed, destructive, and blocked states.

### Neutral

- **Workshop Canvas:** The application background and recessed navigation surface.
- **Instrument Surface:** Tables, inputs, menus, and focused work areas.
- **Graphite Ink:** Primary text and high-confidence data.
- **Quiet Mineral:** Secondary surfaces and selected navigation.
- **Weathered Ink:** Supporting labels and metadata.
- **Hairline Rule:** Structural separators and control boundaries.

### Named Rules

**The Brand Boundary Rule.** Orange identifies Crucible DB; it does not indicate ordinary actions or selected navigation.

**The Semantic Integrity Rule.** Green, amber, red, and blue never decorate neutral content. They always communicate state.

## Typography

**Display Font:** Native system sans-serif

**Body Font:** Native system sans-serif

**Label/Mono Font:** ui-monospace, SFMono-Regular, Menlo, Consolas, monospace

**Character:** Native typography makes the application feel immediate and familiar across platforms. Monospace distinguishes values users must compare exactly.

### Hierarchy

- **Headline** (600, 24px, 1.15): Compact page titles only.
- **Title** (600, 16px, 1.35): Section headings, object names, and primary table values.
- **Body** (400, 14px, 1.5): Interface copy, descriptions, and table content.
- **Label** (600, 12px, 0.04em): Navigation groups, table headings, and metadata labels. Uppercase is limited to short table headings.

### Named Rules

**The Exact Data Rule.** SQL, endpoints, identifiers, timestamps, and database names use monospace; ordinary prose never does.

## Elevation

The system is flat by default. Canvas, surface tone, hairline rules, and spacing establish depth. Shadows are reserved for temporary overlays such as menus, sheets, and dialogs.

### Shadow Vocabulary

- **Overlay:** A broad, low-opacity shadow used only for content that floats above the current workflow.

### Named Rules

**The Work Stays Flat Rule.** Tables, filters, sections, and dashboard content never receive decorative shadows.

## Components

### Buttons

- **Shape:** Compact and gently squared with a 6px radius.
- **Primary:** Action Blue background, light text, 36px height, and icon plus label when recognition benefits.
- **Hover / Focus:** Darker blue on hover; a visible two-pixel outer focus ring with sufficient offset for keyboard users.
- **Secondary / Ghost:** Neutral surfaces and transparent treatments for reversible or contextual actions.

### Chips

- **Style:** Compact neutral background with a hairline boundary. Selected filters use a low-chroma blue tint.
- **State:** Every semantic chip includes a label and may include a reinforcing icon or dot.

### Cards / Containers

- **Corner Style:** 8px radius for true standalone containers.
- **Background:** Instrument Surface over Workshop Canvas.
- **Shadow Strategy:** No shadow at rest.
- **Border:** Hairline Rule only where separation cannot be achieved by spacing.
- **Internal Padding:** 16px for compact regions and 24px for primary form sections.

### Inputs / Fields

- **Style:** Instrument Surface, 6px radius, 36px default height, and explicit labels.
- **Focus:** Action Blue border and visible outer ring.
- **Error / Disabled:** Error includes text and icon; disabled controls reduce contrast without disappearing.

### Navigation

Navigation is grouped by Work, Data, and Admin. Default items remain quiet, hover gains a neutral surface, and the active item uses a pale neutral background with an Action Blue icon. On mobile, navigation becomes a sheet while preserving the same hierarchy.

### Operational Table

Tables are open work surfaces, not cards inside cards. Headers remain visible during long lists, rows expose a clear primary object, metadata is quiet, and actions live on the row they affect. Pagination and result count stay adjacent.

## Do's and Don'ts

### Do:

- **Do** organize navigation around Work, Data, and Admin.
- **Do** keep approval, risk, schedule, execution, and access state visible near the object.
- **Do** use 36px controls and compact table rows for repeated desktop workflows.
- **Do** apply `focus-visible` treatment and reduced-motion alternatives to every interactive component.
- **Do** use progressive disclosure for credentials, transport settings, audit detail, and destructive actions.

### Don't:

- **Don't** resemble a generic CRUD admin template, decorative SaaS dashboard, Cloudflare clone, or toy SQL editor.
- **Don't** use oversized marketing headers, vanity metric cards, nested card layouts, decorative gradients, or glass effects.
- **Don't** add decorative page eyebrows, icon tiles, or trust claims that repeat what the product should prove through behavior.
- **Don't** use colored side-stripe borders, gradient text, or decorative status colors.
- **Don't** separate an object from its next action or hide whether a database operation has run.
- **Don't** introduce a new visual treatment when an existing component already expresses the same state or action.
