---
name: Sovereign Assurance
colors:
  surface: '#f9f9ff'
  surface-dim: '#d3daea'
  surface-bright: '#f9f9ff'
  surface-container-lowest: '#ffffff'
  surface-container-low: '#f0f3ff'
  surface-container: '#e7eefe'
  surface-container-high: '#e2e8f8'
  surface-container-highest: '#dce2f3'
  on-surface: '#151c27'
  on-surface-variant: '#444650'
  inverse-surface: '#2a313d'
  inverse-on-surface: '#ebf1ff'
  outline: '#757681'
  outline-variant: '#c5c6d1'
  surface-tint: '#455c99'
  primary: '#000d2f'
  on-primary: '#ffffff'
  primary-container: '#00205b'
  on-primary-container: '#738aca'
  inverse-primary: '#b2c5ff'
  secondary: '#bb001a'
  on-secondary: '#ffffff'
  secondary-container: '#e2232d'
  on-secondary-container: '#fffbff'
  tertiary: '#0d1011'
  on-tertiary: '#ffffff'
  tertiary-container: '#222527'
  on-tertiary-container: '#8a8c8e'
  error: '#ba1a1a'
  on-error: '#ffffff'
  error-container: '#ffdad6'
  on-error-container: '#93000a'
  primary-fixed: '#dae2ff'
  primary-fixed-dim: '#b2c5ff'
  on-primary-fixed: '#001849'
  on-primary-fixed-variant: '#2d447f'
  secondary-fixed: '#ffdad7'
  secondary-fixed-dim: '#ffb3ad'
  on-secondary-fixed: '#410004'
  on-secondary-fixed-variant: '#930012'
  tertiary-fixed: '#e1e2e4'
  tertiary-fixed-dim: '#c5c6c8'
  on-tertiary-fixed: '#191c1e'
  on-tertiary-fixed-variant: '#444749'
  background: '#f9f9ff'
  on-background: '#151c27'
  surface-variant: '#dce2f3'
typography:
  h1:
    fontFamily: Public Sans
    fontSize: 48px
    fontWeight: '700'
    lineHeight: '1.2'
    letterSpacing: -0.02em
  h2:
    fontFamily: Public Sans
    fontSize: 32px
    fontWeight: '600'
    lineHeight: '1.3'
  h3:
    fontFamily: Public Sans
    fontSize: 24px
    fontWeight: '600'
    lineHeight: '1.4'
  body-lg:
    fontFamily: Inter
    fontSize: 18px
    fontWeight: '400'
    lineHeight: '1.6'
  body-md:
    fontFamily: Inter
    fontSize: 16px
    fontWeight: '400'
    lineHeight: '1.5'
  label-sm:
    fontFamily: Inter
    fontSize: 13px
    fontWeight: '600'
    lineHeight: '1'
    letterSpacing: 0.05em
rounded:
  sm: 0.125rem
  DEFAULT: 0.25rem
  md: 0.375rem
  lg: 0.5rem
  xl: 0.75rem
  full: 9999px
spacing:
  base: 8px
  container-max: 1200px
  gutter: 24px
  margin-mobile: 16px
  section-padding: 80px
---

## Brand & Style

The design system is anchored in the principles of institutional trust, automotive precision, and British heritage. It targets a UK-based audience looking for transparency and reliability in the secondary car market. The visual language balances the "Official" feel of a government service with the premium aesthetic of high-end automotive engineering.

The style is **Corporate Modern** with a heavy emphasis on **Minimalism**. It utilizes generous whitespace to reduce cognitive load during complex data reviews. To subtly reference the UK flag without literalism, the system employs rhythmic uses of diagonal 45-degree lines in backgrounds and strict rectilinear alignments. Automotive themes are hinted at through high-gloss finishes on call-to-action elements and "dashboard-grade" clarity in data visualization.

## Colors

The color palette is derived from the iconic Union Flag, refined for digital legibility and professional appeal. 

- **Primary (Deep Navy):** Used for headers, primary navigation, and "State of Authority" elements. It provides the foundation of trust.
- **Accent (Vibrant Red):** Reserved strictly for primary Call-to-Action (CTA) buttons and critical alerts. It represents urgency and action.
- **Neutrals:** A range of cool grays is used for secondary text and structural borders to maintain a crisp, clean environment.
- **Success/Warning:** While not primary colors, the system utilizes a muted emerald for "Passed" checks and a deep amber for "Advisories," ensuring they don't clash with the patriotic primary palette.

## Typography

The typography strategy leverages **Public Sans** for headlines to evoke an institutional, official tone similar to modern public service interfaces. Its neutral but sturdy construction communicates stability. 

**Inter** is utilized for body copy and UI labels due to its exceptional legibility at small sizes and high "x-height," which is crucial for reading technical vehicle specifications and VIN numbers. 

Letter spacing is tightened on large headlines for a more premium, editorial feel, while labels are given slight tracking (letter-spacing) to improve scanability in data-heavy reports.

## Layout & Spacing

This design system employs a **Fixed Grid** model for desktop to ensure the data reports remain legible and centered on high-resolution monitors. The layout is built on a 12-column grid with a 24px gutter.

A strict 8px spatial scale governs all padding and margin decisions, creating a rhythmic and predictable flow. Vertical rhythm is prioritized, with generous section padding (80px+) to allow the brand's premium identity to "breathe" and to separate different phases of the vehicle check process (e.g., Vehicle ID vs. History vs. Finance).

## Elevation & Depth

To maintain a professional and trustworthy atmosphere, the design system avoids heavy shadows in favor of **Tonal Layers** and **Ambient Shadows**.

1.  **Level 0 (Base):** Crisp White (#FFFFFF) background for the main canvas.
2.  **Level 1 (Cards):** Subtle 1px borders (#E5E7EB) with a very soft, diffused shadow (0px 4px 20px rgba(0, 32, 91, 0.04)) to lift report sections off the page.
3.  **Level 2 (Interactive):** Elements like search bars and hover-state cards use a slightly more pronounced shadow and a thin Navy border to indicate focus.
4.  **Accent Depth:** Primary CTA buttons use a subtle inner-glow on the top edge to simulate the tactile feel of a high-end car dashboard button.

## Shapes

The shape language is **Soft (0.25rem)**, reflecting automotive engineering precision. We avoid overly rounded or "bubbly" corners to maintain a serious, institutional tone.

- **Buttons & Inputs:** Use a 4px (0.25rem) radius.
- **Content Cards:** Use an 8px (0.5rem) radius to provide a gentle container for vehicle data.
- **Progress Indicators:** Linear elements should use flat caps, while circular indicators (like 'Score' rings) should use clean, non-rounded terminals to maintain a technical, instrument-cluster aesthetic.

## Components

### Buttons
- **Primary:** Solid Vibrant Red with white text. High-contrast, sharp 4px corners. On hover, the color deepens slightly to a rich crimson.
- **Secondary:** Deep Navy outline with Navy text. Used for secondary actions like "Download PDF."
- **Tertiary:** Ghost style with no border, using Navy text for "Learn More" or "View Example" links.

### Input Fields
The VIN Search Bar is the "Hero" component. It should be oversized, with a white background and a subtle 1px gray border. Upon focus, the border transitions to Deep Navy. Use a monospaced font specifically for the VIN input to ensure '0' and 'O' or '1' and 'I' are distinguishable.

### Vehicle Report Cards
Data is organized into "Modules." Each module has a light gray header with a Deep Navy label. Pass/Fail status is indicated by a colored left-hand border (4px width) — Green for pass, Red for fail, Amber for advisory.

### Chips & Badges
Used for vehicle attributes (e.g., "Ulez Compliant," "Manual," "Diesel"). These use a light gray background with Navy text to remain neutral and not compete with the primary Call-to-Action.

### The "Sovereign" Line
A subtle design element used as a separator: a thin 1px gray line that features a small 2-pixel segment of Vibrant Red on the far left, hinting at the precision of the brand's identity.