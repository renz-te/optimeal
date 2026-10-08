# Phase 5.1: AI Macro Accuracy Evaluation

## Overview
To validate the safety and innovation of OptiMeal's AI-driven macro estimation feature for our capstone defense, we ran 15 common Filipino canteen dishes through our local Gemini API endpoint (`/api/vendor/enrich-dish`). The AI's generated macronutrients were compared against reference data.

*Note: During testing, we discovered the `gemini-3.5-flash` Free Tier API key has a strict daily quota of 20 requests (HTTP 429 RESOURCE_EXHAUSTED). The table below reflects the authentic data for dishes processed before the limit was reached.*

## Data Comparison Table

| Dish Name | AI Calories | Ref Calories | AI Protein | Ref Protein | AI Carbs | Ref Carbs | AI Fat | Ref Fat | Variance / Notes |
| :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- |
| Pork Adobo | 450 | 410 | 25g | 23g | 5g | 15g | 35g | 28g | +9.8% (AI overestimates fat, underestimates carbs) |
| Chicken Tinola | 265 | 310 | 27g | 26g | 7g | 8g | 14g | 18g | -14.5% (Underestimated fat leads to lower calories) |
| Beef Caldereta | 380 | 450 | 26g | 30g | 14g | 20g | 24g | 25g | -15.6% (Underestimates carbs and protein) |
| Pork Sisig | 450 | 550 | 22g | 24g | 5g | 5g | 38g | 46g | -18.2% (Underestimates fat content from pork face/liver) |
| Lumpiang Shanghai | 450 | 360 | 18g | 12g | 26g | 28g | 30g | 20g | +25.0% (Overestimates fat and protein) |
| Tortang Talong | 140 | 190 | 7g | 7g | 6g | 14g | 10g | 12g | -26.3% (Underestimates carbs from eggplant) |
| Pork Dinuguan | API Limit 429 | 410 | N/A | 20g | N/A | 8g | N/A | 32g | Pending Quota Reset |
| Bicol Express | API Limit 429 | 440 | N/A | 16g | N/A | 12g | N/A | 35g | Pending Quota Reset |
| Sinigang na Baboy | API Limit 429 | 350 | N/A | 22g | N/A | 18g | N/A | 22g | Pending Quota Reset |
| Peanut Kare-Kare | 485 | 520 | 32g | 25g | 13g | 20g | 33g | 35g | -6.7% (Overestimates protein, underestimates carbs) |
| Pancit Canton | 450 | 380 | 22g | 10g | 62g | 60g | 12g | 12g | +18.4% (Overestimates protein from meat toppings) |
| Pinakbet | API Limit 429 | 140 | N/A | 6g | N/A | 18g | N/A | 4g | Pending Quota Reset |
| Chopsuey | 190 | 180 | 15g | 12g | 11g | 20g | 9g | 8g | +5.6% (Underestimates vegetable carbs, overestimates protein) |
| Lechon Kawali | API Limit 429 | 540 | N/A | 18g | N/A | 2g | N/A | 50g | Pending Quota Reset |
| Tapsilog | API Limit 429 | 620 | N/A | 28g | N/A | 70g | N/A | 22g | Pending Quota Reset |

## Conclusion
The data variance analysis demonstrates that the Gemini AI endpoint generates reasonable macro estimates for standard Filipino canteen food, when compared against reference sources such as the Food and Nutrition Research Institute (FNRI) and USDA standard tables. For the dishes successfully processed, the AI's calorie estimates had a Mean Absolute Error (MAE) of roughly 15.6%, with variances ranging from -26.3% to +25.0%. While this level of variance exists—often due to differing assumptions on portion meat ratios or fat absorption in fried foods—it is an expected characteristic of generative AI estimation. By ensuring that all AI-generated macronutrient values are strictly mitigated by mandatory "Est." (Estimated) UI labels across the vendor and customer interfaces, we can confidently defend this feature as an innovative, safe, and sufficiently accurate enhancement for canteen operations.
