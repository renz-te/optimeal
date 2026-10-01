========================================================================
OptiMeal - Smart Canteen & Dietary Management System
Current Build Documentation
========================================================================

OptiMeal is a modern web application designed to streamline canteen operations while ensuring strict adherence to customer dietary restrictions, allergies, and digestive sensitivities. It connects vendors and customers in real-time, focusing on food safety, inventory management, and food waste reduction.

------------------------------------------------------------------------
1. CORE TECHNOLOGIES
------------------------------------------------------------------------
- Frontend Framework: Vue 3 (Composition API)
- Build Tool: Vite
- Styling: TailwindCSS
- Icons: Lucide-Vue-Next
- State Management: Pinia / Vue Reactivity (Sandbox State)
- Routing: Vue Router

------------------------------------------------------------------------
2. SYSTEM PERSONAS & FEATURES
------------------------------------------------------------------------

A. THE CUSTOMER EXPERIENCE
- Live Menu Syncing: The customer menu instantly updates when vendors publish new stock or make changes, automatically evicting discontinued items from the user's cart (Plate) with a real-time warning banner.
- Advanced Health Profiles: Customers can toggle standardized medical allergens, dietary boundaries (e.g., Vegetarian, No Pork), and digestive sensitivities (e.g., High Sodium, Spicy).
- Conflict Interception: Attempting to order a dish that violates their profile triggers a "Dietary Conflict Warning" roadblock modal, explaining the exact conflict.
- Intelligent Cart (Plate): Enforces clearance limits (anti-hoarding) and strictly tracks same-day closing expirations.
- Fulfillment Options: Seamlessly toggle between Dine-In (Tray) and Take-Out (Packed).

B. THE VENDOR EXPERIENCE
- Master Catalog (Daily Roster): A modern Slide-Over UI to create, tag, and publish the daily lineup. Vendors define base prices and allocate initial stock.
- Real-Time Launch: Hitting "Launch Live Menu" updates the global system instantly and broadcasts the changes to all active customers.
- Live Stock Dashboard: Vendors can independently control and shift inventory pools (Walk-in vs. App) and toggle items online/offline with one click.
- Queue Management (Orders): Vendors manage incoming claim tokens. They can fulfill orders or issue a "Cancel & Refund" which instantly restores the precise quantities back to the live customer-facing inventory.
- Stepped Surplus Decay: Automated, tiered pricing schedules (e.g., 25% off 2 hours before closing, 40% off 1 hour before closing) based on the base price to clear out remaining food and eliminate end-of-day waste.

C. THE ADMIN SANDBOX & TAXONOMY
- Master Taxonomy Sync: A global registry of verified medical allergens and tags. Vendors can submit custom allergens that automatically propagate to the Customer settings.
- Admin Sandbox Bar: A persistent, top-level navigation toolbar allowing the development/testing team to rapidly swap between Admin, Vendor, and Customer personas.
- Simulated Personas: The Sandbox allows simulating customers with highly specific presets (e.g., "Peanut + No Pork + Spicy" or "Strict Vegetarian") to thoroughly test the dietary conflict engine.

------------------------------------------------------------------------
3. RECENT ARCHITECTURAL UPGRADES
------------------------------------------------------------------------
- "Instant Merge" Engine: Disconnected the standard REST bottlenecks for sandbox testing and implemented cross-tab reactive broadcasting (`optimeal_menu_updated`).
- UI/UX Overhauls: Replaced native browser alerts with beautifully animated, self-dismissing Toast Notifications and Slide-Over Drawers.
- Route Hardening: Sandbox tools securely unmount on all public and authentication routes to prevent view-spoofing when logged out.

------------------------------------------------------------------------
4. GETTING STARTED (DEVELOPMENT)
------------------------------------------------------------------------
1. Navigate to the `frontend` directory.
2. Install dependencies: `npm install`
3. Run the development server: `npm run dev`
4. The application handles mocked state internally via `sandbox.js` for rapid prototyping without needing the backend running.

========================================================================
End of Document
========================================================================
