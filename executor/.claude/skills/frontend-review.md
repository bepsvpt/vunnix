---
version: "1.0"
updated: "2026-02-14"
---
# Frontend Review Skill

You are reviewing a merge request that contains frontend changes (`.vue`, `.tsx`, `.css` files). Analyze the diff and related files systematically using the checklist below. Classify each finding using the severity definitions from your system instructions.

## Review Checklist

### 1. Component Structure

- **Single Responsibility:** Each component should have one clear purpose. Flag components that mix unrelated concerns (e.g., data fetching + complex UI logic + form validation in one file).
- **Composition API:** Verify `<script setup>` usage with Composition API. Flag Options API usage (`data()`, `methods`, `computed`, `watch` as object keys) — the project standard is Composition API.
- **Props & Emits:** Check that props have type declarations and required/default annotations. Verify `defineEmits` is used for custom events (not `$emit` without declaration). Check for excessive prop drilling — suggest `provide/inject` or Pinia when props pass through 3+ levels.
- **Template Complexity:** Flag templates with deeply nested logic (v-if/v-else chains > 3 levels). Suggest extracting computed properties or child components.
- **Component Naming:** PascalCase for component files and usage in templates. Multi-word names to avoid HTML element conflicts.

### 2. Reactivity Patterns

- **Ref vs Reactive:** `ref()` for primitives and values that get reassigned. `reactive()` for objects that are mutated in place. Flag `reactive()` on primitives or `ref()` where `.value` is never needed.
- **Computed Properties:** Verify computed properties are used for derived state instead of watchers that manually set values. Flag side effects inside `computed()`.
- **Watch Usage:** Check that `watch` / `watchEffect` have cleanup functions where needed (timers, event listeners, subscriptions). Flag watchers that could be replaced with `computed`.
- **Reactivity Loss:** Flag destructuring of reactive objects without `toRefs()`. Flag reassigning the entire value of a `reactive()` object (breaks reactivity). Check for `.value` access on non-ref values.
- **Lifecycle Hooks:** Verify cleanup in `onUnmounted` for timers, listeners, and subscriptions registered in `onMounted`.

### 3. Accessibility

- **Semantic HTML:** Verify proper element usage — `<button>` for actions (not `<div @click>`), `<a>` for navigation, heading hierarchy (`h1` → `h2` → `h3`), landmark elements (`<nav>`, `<main>`, `<aside>`).
- **ARIA Attributes:** Interactive elements must have accessible names (`aria-label`, `aria-labelledby`, or visible text). Check that `aria-hidden="true"` is not applied to focusable elements. Verify `role` attributes are used correctly.
- **Keyboard Navigation:** Interactive elements must be keyboard-accessible. Check `tabindex` usage — avoid positive values. Verify custom components handle `Enter` and `Space` for activation, `Escape` for dismissal.
- **Form Accessibility:** Labels associated with inputs (`<label for>` or wrapping). Error messages linked via `aria-describedby`. Required fields indicated with `aria-required`.
- **Focus Management:** Modal/dialog components must trap focus. After dynamic content changes (route navigation, tab switching), focus should move to the new content or an appropriate landmark.
- **Color & Contrast:** Flag color as the sole indicator of state (error = red only, without icon or text). Verify text contrast meets WCAG AA (4.5:1 for normal text, 3:1 for large text) where determinable from code.

### 4. CSS Specificity & Styling

- **Scoped Styles:** Verify `<style scoped>` is used in components to prevent style leakage. Flag unscoped styles unless they are intentionally global.
- **Specificity Conflicts:** Flag `!important` declarations — suggest restructuring selectors instead. Flag overly specific selectors (IDs in component styles, chains of 4+ selectors).
- **Design Tokens:** If the project defines design tokens (CSS custom properties, theme variables), verify new styles reference them instead of hardcoding values for colors, spacing, typography, shadows, and border radii.
- **Responsive Design:** Check that new UI elements work across breakpoints. Flag fixed pixel widths on containers that should be fluid. Verify media queries follow the project's breakpoint convention.
- **CSS Organization:** Flag duplicate property declarations within the same selector. Check for dead CSS (selectors that match no elements in the component template).

### 5. Internationalization (i18n)

- **Hardcoded Strings:** Flag user-facing text strings hardcoded in templates or script. These should use the project's i18n system (e.g., `$t('key')`, `useI18n()`).
- **Dynamic Content:** Verify that interpolated messages handle pluralization and variable substitution correctly. Flag string concatenation for translated text — use parameterized messages instead.
- **Attribute Text:** Check that `placeholder`, `title`, `alt`, and `aria-label` attributes use translated strings.

### 6. Performance

- **List Rendering:** Verify `v-for` loops include a unique `:key` binding (not array index for mutable lists). Flag `v-for` + `v-if` on the same element — use `computed` to filter first.
- **Expensive Computations:** Flag heavy computations in templates — these re-run on every render. Suggest `computed` or `useMemoize`.
- **Event Handling:** Check that scroll/resize/input handlers are debounced or throttled where appropriate. Flag inline functions in templates that create new closures on every render if used as props to child components.
- **Lazy Loading:** For large components or route-level components, check whether `defineAsyncComponent` or dynamic `import()` is appropriate.

## ESLint Integration

If eslint results are available, classify each eslint finding through the severity system:

- **Error-level rules** with `plugin:vue/vue3-essential` → 🟡 Major (these catch actual bugs)
- **Error-level rules** with security implications → 🔴 Critical
- **Warning-level rules** → 🟢 Minor
- **Fixable issues** (auto-fixable by eslint) → 🟢 Minor with note that auto-fix is available

Include eslint findings in the `findings` array with `category: "convention"` and reference the specific rule name in the `title`.

## Stylelint Integration

If stylelint results are available, classify similarly:

- **Errors** related to invalid CSS or browser compatibility → 🟡 Major
- **Warnings** for convention violations → 🟢 Minor

## Large Diff Handling

For merge requests with many changed files:

- Focus on files with the most significant changes first
- Follow cross-file references from changed components (imports, props passed from parent, events emitted to parent, shared composables, store usage)
- Summarize patterns across similar changes (e.g., "12 components updated to use new Button variant — spot-checked 3, all follow the same pattern")
- Prioritize review depth: new files > significantly modified files > minor changes (imports, formatting)

## Output

Produce a JSON object matching the code review schema. The `summary.walkthrough` should describe what each changed frontend file does. Each finding must reference a specific `file` and `line`. Use diff suggestions in the `suggestion` field where a concrete fix is possible.

Set `commit_status` to `"failed"` only if there are 🔴 Critical findings. Otherwise set `"success"`.

Set `labels` to include `"ai::reviewed"` always, plus `"ai::risk-high"`, `"ai::risk-medium"`, or `"ai::risk-low"` based on the overall `risk_level`.
