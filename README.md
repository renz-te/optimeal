# OptiMeal

## AI-Assisted Menu Enrichment

As part of the capstone project's core innovation, OptiMeal integrates an AI-driven menu enrichment feature utilizing the Google Gemini API. This feature fundamentally reduces vendor onboarding friction while safely providing estimated nutritional data to end-users.

### Vendor Experience
Creating detailed digital menus can be time-consuming for small canteen vendors. We integrated an "Auto-fill with AI ✨" button directly into the vendor's Add/Edit Dish drawer. When a vendor enters a simple dish name and portion (e.g., "Pork Adobo"), clicking the button triggers the backend to fetch estimated macronutrients (calories, protein, carbs, fat) and a likely ingredients list. The vendor can then review and edit the populated form before confirming and saving the dish.

### AI Macro Estimation
The integration uses the `gemini-3.5-flash` model, guided by strict system prompts. The AI is instructed to return only deterministic JSON structures containing macro estimates and base ingredients. The estimates are grounded in standard culinary data, giving campus students an accessible way to monitor their nutritional intake (with all AI values explicitly labeled as "estimated" in the UI).

### Safety Architecture (Sandboxing the AI)
A primary concern with LLM integrations in food service is allergen hallucination. OptiMeal employs a strict "sandboxed" architecture to mitigate this risk. 
- The AI **only** returns a list of raw ingredients (e.g., "Peanut Butter", "Shrimp").
- The AI is **never** responsible for tagging or identifying critical allergens directly. 
- When the vendor saves the dish, the backend iterates through the ingredients and cross-references a highly structured, relational lookup table (`ingredient_allergen_map`). 
- If an ingredient matches a known allergen, the system automatically tags the menu item and flags it in the dietary conflict engine. This ensures that the application's safety guarantees remain deterministic and mathematically sound, independent of the AI's behavior.

### Performance & Rate Limit Architecture
To ensure system resilience and optimize API usage constraints (such as Free Tier Rate Limits of HTTP 429), we implemented a database-backed caching layer (`ai_query_cache`).
- **Caching**: Upon a successful Gemini API request, the dish name (normalized) and its exact JSON response payload are saved to the database. Subsequent requests for the same dish bypass the API entirely, returning the cached payload instantly.
- **Graceful Fallback**: If the Gemini API limit is exhausted, the backend intercepts the failure and returns a structured `503 Service Unavailable` response with a `fallback: true` flag. The frontend gracefully degrades, prompting the vendor to manually input the ingredients and macros, ensuring that canteen operations are never blocked by external service outages.
