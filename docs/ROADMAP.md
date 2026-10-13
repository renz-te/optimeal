--- ROADMAP ---
Order: Step 1 -> 2 -> 3 -> 4 -> 5 -> 6. Each step must leave the app demo-ready.
Step 1 (D9b): bulk price and rollback fixes, admin closing-time wording, Prep Plan cost total, B3.2 closing-time gap check.
Step 2 (D10, notifications): vendors send notifications (roster published, a discount tier starting, last clearance); customers are notified about their pre-cooked reservations. Polling about every 20 s, bell icon with unread count. In-app only; browser or mobile push is a documented next step.
Step 3 (D11, Settings): profile name and email, change password, notification preferences, for customer and vendor. Server-side checks on every change; password change requires the current password.
Step 4 (D12, payment): payment provider interface with a mock "Test mode" provider that confirms or fails a payment. A real PayMongo adapter slots in later by swapping the provider and adding keys. Label clearly as Test mode in the UI and README.
Step 5 (B3.3, admin users): list users, change role, toggle active/inactive. Impersonation only if time allows, with a server-side check, no impersonating admins, an audit log, and a visible "return to admin" bar.
Step 6 (before defense): read-only audit, clean demo data with cleanup_test_data.sql then seed fresh demo data (keep dish photos), update README, add .env.example, DB dump, demo rehearsal.
--- END ROADMAP ---
