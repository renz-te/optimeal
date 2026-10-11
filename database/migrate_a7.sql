-- Phase A7: Decay & Settings Unification Migration
-- Adds surplus decay configuration and emergency clearance columns to the existing settings table.
-- Existing columns and tables are strictly preserved.

USE optimeal;

ALTER TABLE settings 
ADD COLUMN tier1_minutes_before_close INT NOT NULL DEFAULT 120,
ADD COLUMN tier1_discount_percent DECIMAL(5, 2) NOT NULL DEFAULT 25.00,
ADD COLUMN tier2_minutes_before_close INT NOT NULL DEFAULT 60,
ADD COLUMN tier2_discount_percent DECIMAL(5, 2) NOT NULL DEFAULT 40.00,
ADD COLUMN emergency_clearance_active BOOLEAN NOT NULL DEFAULT FALSE,
ADD COLUMN emergency_activated_at TIMESTAMP NULL DEFAULT NULL;
