# OptiMeal Design System

Source of truth for the look of OptiMeal. Read this before creating or changing any screen.
Written from the styling already used in `frontend/src` (Vue 3 + Tailwind CSS 3). When this file and a design skill disagree, this file wins. Existing screens keep their current style unless the task says to change them.

## 1. Stack and constraints
- Vue 3 (Composition API), Vite, Tailwind CSS 3. No custom CSS: `style.css` only imports Tailwind. Use utility classes.
- Icons: `lucide-vue-next`. Use it for every new icon. Do not add emoji or inline SVG icons.
- Font: **Plus Jakarta Sans** (400, 500, 600, 700, 800), set as `font-sans` in `tailwind.config.js`. No other fonts.
- Page base: `bg-slate-50 text-slate-900 font-sans antialiased`.

## 2. Color roles
Use roles, not decoration. Do not introduce new hues.

| Role | Tailwind classes | Used for |
| :--- | :--- | :--- |
| Primary | `emerald-600` (hover `emerald-700`), tints `emerald-50/200`, text `emerald-700` | Main buttons, active nav, focus, "safe" status, success |
| Neutral | `slate-50` page, `white` cards, `slate-100` fills, `slate-200` borders, `slate-300` input borders | Surfaces and borders |
| Text | `slate-900` headings, `slate-700/600` body, `slate-500` secondary, `slate-400` placeholder and disabled | Typography |
| Dark chrome | `slate-900` sidebar and bars, `slate-800/700` hover and borders, `emerald-400` brand text on dark | Staff and admin sidebar, sandbox bars |
| Danger | `rose-50` fill, `rose-200` border, `rose-700` text | **Critical allergens**, conflict warnings, errors, logout |
| Caution | `amber-50` fill, `amber-200` border, `amber-700` text | Digestive sensitivities, "needs review" notices, the AI confirmation box |
| AI | `indigo-50` fill, `indigo-200` border, `indigo-600` text | AI actions and AI-related labels only |

Rules:
- Rose always means a critical allergen or an error. Never use rose for decoration.
- Amber always means caution or "needs your review". Never use it for success.
- Emerald is the only primary. Do not add blue, purple, or orange accents.

## 3. Typography
| Use | Classes |
| :--- | :--- |
| Brand wordmark (dark sidebar) | `text-2xl font-black text-emerald-400 tracking-tight` |
| Brand wordmark (customer nav) | `text-xl font-bold text-emerald-600 tracking-tight` |
| Page title | `text-3xl font-black text-slate-900 tracking-tight` |
| Section or card title | `text-lg font-semibold` or `font-bold text-slate-800` |
| Body | `text-sm text-slate-600` |
| Secondary text | `text-xs text-slate-500` |
| Labels and badges | `text-[10px]` or `text-[9px]`, `font-bold uppercase tracking-wider` |

Weights in use: `font-bold` (most), `font-semibold`, `font-medium`, `font-black` (titles and brand only).

## 4. Shape, depth, spacing
- Radius: controls and buttons `rounded-lg`; cards `rounded-xl`; large panels `rounded-2xl`; pills and badges `rounded-full`.
- Depth: cards `shadow-sm` with `border border-slate-200`; hover `shadow-md`; drawers and modals `shadow-2xl`.
- Page width: customer pages `max-w-6xl mx-auto p-4`. Staff and admin pages use `p-8` inside the dashboard layout.
- Card grid: `grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6`.
- Mobile first. `md:` is the main breakpoint (the sidebar is hidden below `md`).

## 5. Components (copy these patterns)
**Primary button**
`bg-emerald-600 text-white font-bold px-4 py-2 rounded-lg hover:bg-emerald-700 shadow-sm transition-colors`
Full-width and tap feedback: add `w-full py-3.5 transition-all active:scale-95`.
Disabled: `bg-slate-100 text-slate-400 cursor-not-allowed`.

**AI button** (the only indigo element)
`text-xs font-bold bg-indigo-50 text-indigo-600 px-4 py-2 rounded-lg border border-indigo-200 hover:bg-indigo-100 transition-colors flex items-center gap-1.5 disabled:opacity-50`

**Text input**
`w-full px-4 py-3 rounded-lg border border-slate-200 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-200 outline-none transition-all`

**Card**
`bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden`

**Badge or chip**
`inline-flex items-center text-[10px] uppercase font-bold px-2 py-0.5 rounded-full border`
- Critical allergen: `bg-rose-50 text-rose-700 border-rose-200` with an `AlertTriangle` icon.
- Sensitivity: `bg-amber-50 text-amber-700 border-amber-200`.
- Safe: `bg-emerald-50 text-emerald-700 border-emerald-200`.
- Neutral tag: `bg-slate-100 text-slate-600`.

**Dish card states**
- Normal: `border border-slate-200`.
- Conflicts with the student's profile: `border-2 border-rose-200 bg-rose-50/30 opacity-75`.
- Clearance badge: top-right pill with a `Clock` icon and the discount percent.

**Review notice (amber)**
`p-3 bg-amber-50 border border-amber-200 rounded-lg`. The AI confirmation checkbox uses this.

**Modal overlay**
`fixed inset-0 bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4` with a `bg-white rounded-2xl shadow-2xl max-w-sm w-full overflow-hidden` panel. The dietary conflict warning has a `bg-rose-50` header band with a round `AlertTriangle` icon and a `text-xl font-black text-rose-900` title.

**Side drawer**
`fixed right-0 top-0 h-full w-full max-w-md bg-white shadow-2xl flex flex-col`.

**Toast**
Success: `bg-emerald-900 text-emerald-100 border border-emerald-700`. Error: `bg-rose-900 text-rose-100 border border-rose-700`. A small pulsing dot sits before the message.

**Navigation**
- Customer top bar: `bg-white/80 backdrop-blur border-b border-slate-200 sticky top-0`. Active link `bg-emerald-50 text-emerald-700`.
- Staff and admin sidebar: `w-64 bg-slate-900 text-slate-300`. Active link `bg-emerald-600/20 text-emerald-400 border border-emerald-500/30 font-semibold`.

## 6. Content and safety rules for UI
- AI-generated nutrition values are labeled **"Est."** everywhere a student sees them.
- Allergens are shown from the allergen lookup, never from AI text. Critical allergens always use the rose style.
- Never show a success state for an action that failed. Show the server's error message in an error toast.
- Every screen needs loading, empty, and error states.
- Interactive elements need visible focus (`focus:ring-2`) and readable contrast.

## 7. Known inconsistencies (do not copy these; fix only when asked)
- The clearance badge uses `red-600` while all other danger UI uses `rose`.
- The AI button uses emoji (⏳ ✨) while the rest of the app uses lucide icons. The dish image placeholder is an inline SVG.
- Brand text differs: `text-xl font-bold emerald-600` (customer) vs `text-2xl font-black emerald-400` (sidebar).
- z-index values are scattered (40, 50, 60, 80, 90, 100). Pick from the existing range for new overlays and do not invent new values.
- Buttons mix `rounded-lg` and plain `rounded`. Use `rounded-lg`.
- Two admin sandbox bars exist (`AdminSandboxBar` and `AdminSimulationBar`).

## 8. Working with design skills
- `design-taste-frontend`: use for **new** screens, and only inside the roles, fonts, radius, and components above. Do not change the palette, font, or the look of existing screens.
- `web-design-guidelines`: use to review changed UI for accessibility and interface quality. Apply its fixes without changing the look defined here.
