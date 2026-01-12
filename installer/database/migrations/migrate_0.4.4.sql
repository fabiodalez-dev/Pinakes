-- Migration: 0.4.4
-- Description: Hotfix for updater extraction issues, no database changes

-- This migration contains no database changes.
-- It exists only to mark the version transition.

-- Changes in 0.4.4:
-- 1. Fixed Updater temp directory fallback (use storage/tmp if sys_get_temp_dir not writable)
-- 2. Fixed update progress UI (steps marked complete only on success)
-- 3. Added log viewer endpoint (/admin/updates/logs)
-- 4. Added simple markdown parsing for changelog display
-- 5. Added manual-update.php emergency script

-- End of migration
