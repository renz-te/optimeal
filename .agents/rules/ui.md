# UI rules
- DESIGN.md is the source of truth for this project's look. Read it before any screen change.
- Existing screens keep their current style. Do not restyle screens that are not part of the task.
- For new screens, apply the design-taste-frontend skill only within the colors, fonts, and spacing in DESIGN.md.
- After UI changes, review with the web-design-guidelines skill and fix what it finds.
- Verify visual changes with the Playwright MCP: open the changed page on localhost and screenshot it at phone and desktop width. If Playwright is disabled, use the built-in browser.
- Reuse existing components and Tailwind classes. Every screen needs loading, empty, and error states.
- Use lucide-vue-next for icons. Do not add emoji icons.
- Label AI-generated nutrition values "Est." and never show a success state for a failed action.
