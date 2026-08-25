-- Phase 11.6: normalize all persisted demo currency codes to USD.
-- Presentation uses the $ symbol; USD remains the database currency code.
UPDATE transactions SET currency='USD' WHERE currency IS NULL OR currency='' OR currency<>'USD';
UPDATE account_types SET currency='USD' WHERE currency IS NULL OR currency='' OR currency<>'USD';
UPDATE transfer_limits SET currency='USD' WHERE currency IS NULL OR currency='' OR currency<>'USD';
