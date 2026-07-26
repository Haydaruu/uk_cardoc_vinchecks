---
name: frontend-design-system
description: "Apply this skill whenever writing, reviewing, or refactoring frontend code using React, Inertia.js, Tailwind CSS, or Blade. This includes pages, layouts, reusable components, forms, navigation, dashboards, authentication screens, tables, modals, cards, responsive layouts, animations, accessibility improvements, and UI refactoring. Always follow the project's design system and never introduce new visual styles unless explicitly requested."
license: MIT
metadata:
  author: sovereign
---

# Frontend Design System

This skill defines the UI architecture, design consistency, Tailwind conventions, component structure, and accessibility standards for every frontend implementation.

---

# Consistency First

Before writing any UI:

Inspect nearby pages and reusable components.

Follow the same spacing.

Follow the same typography.

Follow the same color palette.

Follow the same component composition.

Never introduce a second design language into the project.

Consistency is always more important than creativity.

---

# Design System

This project already has an established design system.

Always use these design tokens.

## Colors

Primary colors

- bg-primary
- text-primary
- bg-secondary
- text-secondary

Surface colors

- bg-surface
- bg-surface-container
- bg-surface-container-low
- bg-surface-container-high
- bg-surface-container-highest

Text colors

- text-on-surface
- text-on-surface-variant
- text-on-primary
- text-on-secondary

Outline

- border-outline
- border-outline-variant

Background

- bg-background
- text-on-background

Error

- bg-error
- text-error
- bg-error-container
- text-on-error-container

Never hardcode colors like:

❌ bg-blue-500

❌ text-red-600

❌ bg-slate-200

unless explicitly requested.

Always use the design tokens.

---

# Typography

Two fonts exist.

Heading

Public Sans

Body

Inter

Examples

```
<h1 class="font-sans font-extrabold">
```

```
<p class="font-body">
```

Do not use arbitrary fonts.

---

# Border Radius

Always use

- rounded-sm
- rounded-md
- rounded-lg
- rounded-xl

Avoid arbitrary values.

---

# Glass Components

When a floating card is needed, use

```
glass-card
```

instead of recreating backdrop blur styles.

---

# Decorative Divider

Use

```
sovereign-line
```

instead of manually creating divider elements.

---

# Icons

Material Symbols are the project's standard icon library.

Use

```
material-symbols-outlined
```

Never mix Heroicons, FontAwesome, RemixIcon, Bootstrap Icons, Lucide, or other icon systems unless requested.

---

# Layout Rules

Pages should generally follow this hierarchy

```
Page

    Layout

        Header

        Content

        Sidebar

        Footer
```

Avoid deeply nested wrappers.

Prefer clean hierarchy.

---

# Spacing

Use Tailwind spacing scale.

Preferred

```
p-4
p-6
p-8

gap-4
gap-6
gap-8

space-y-4
space-y-6
space-y-8
```

Avoid arbitrary spacing like

```
mt-[37px]
```

unless absolutely necessary.

---

# Responsive Design

Every page must work on

- Mobile
- Tablet
- Laptop
- Desktop

Use responsive utilities.

Example

```
grid
grid-cols-1
lg:grid-cols-2
xl:grid-cols-3
```

Never build desktop-only layouts.

---

# Component Rules

Prefer reusable components.

Extract repeated UI into components.

Good

```
Button
Card
Input
Badge
Modal
Dropdown
Navbar
Sidebar
Table
Pagination
Alert
```

Avoid duplicated markup.

---

# Forms

Every form should have

- label
- helper text (if needed)
- validation message
- disabled state
- loading state
- focus state

Inputs should have consistent spacing.

---

# Tables

Tables should

- collapse correctly
- scroll horizontally on mobile
- have proper row spacing
- have hover states
- have empty states

---

# Buttons

Every button should clearly indicate

Primary

Secondary

Danger

Outline

Ghost

Loading

Disabled

Never create inconsistent button styles.

---

# Accessibility

Every page should include

Proper heading hierarchy

aria-label where necessary

Keyboard navigation

Visible focus states

Sufficient color contrast

Meaningful button labels

Semantic HTML

Never rely only on color to communicate information.

---

# Animations

Animations should be subtle.

Prefer

```
transition
duration-200
ease-in-out
```

Avoid excessive animation.

---

# Dark Mode

Dark mode is already supported.

Always ensure new UI works in

```
dark:
```

variants when applicable.

Never hardcode white backgrounds.

---

# Performance

Avoid unnecessary rerenders.

Lazy load heavy components.

Avoid deeply nested conditional rendering.

Prefer lightweight components.

---

# React / Inertia Rules

Prefer

Small reusable components

Single responsibility

Reusable hooks

Shared layouts

Reusable form components

Avoid giant page components exceeding roughly 300 lines.

---

# Code Style

Prefer

Readable JSX

Meaningful variable names

Consistent ordering

Minimal nesting

Extract repeated logic.

---

# What To Avoid

Never introduce a different color palette.

Never hardcode colors.

Never hardcode fonts.

Never duplicate components.

Never mix multiple icon libraries.

Never ignore responsiveness.

Never sacrifice accessibility for appearance.

Never use inline styles when Tailwind utilities already exist.

Never create inconsistent spacing.

Never create inconsistent border radius.

---

# Decision Rules

When implementing UI:

1. Check existing components first.
2. Reuse existing patterns.
3. Follow the design tokens.
4. Keep the interface clean.
5. Prioritize consistency over originality.
6. Build mobile-first.
7. Ensure accessibility.
8. Minimize unnecessary complexity.
9. Match the project's visual language.
10. If unsure, imitate the surrounding UI rather than inventing a new style.