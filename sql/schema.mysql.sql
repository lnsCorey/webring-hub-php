-- Webring Hub — MySQL / MariaDB Schema (Hostinger Premium compatible)
-- Character set: utf8mb4 (supports emoji in bios, display names, and tags)

CREATE TABLE IF NOT EXISTS entries (
  rowid BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  id VARCHAR(255) NOT NULL,
  site_url VARCHAR(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  did VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  registrable_domain VARCHAR(255) NOT NULL DEFAULT '',
  handle VARCHAR(100) NOT NULL DEFAULT '',
  display_name VARCHAR(200) NOT NULL,
  tagline VARCHAR(400) NOT NULL DEFAULT '',
  bio TEXT NOT NULL,
  location VARCHAR(200) NOT NULL DEFAULT '',
  avatar_url TEXT NOT NULL,
  banner_url TEXT NOT NULL,
  interests_json MEDIUMTEXT NOT NULL,
  interests_text TEXT NOT NULL,
  links_json MEDIUMTEXT NOT NULL,
  public_key_pem TEXT NOT NULL,
  profile_updated_at VARCHAR(40) NOT NULL,
  verified_at VARCHAR(40) NOT NULL,
  last_checked_at VARCHAR(40) NOT NULL,
  fail_count INT NOT NULL DEFAULT 0,
  last_error VARCHAR(500) DEFAULT NULL,
  status ENUM('active', 'stale', 'blocked') NOT NULL DEFAULT 'active',
  created_at VARCHAR(40) NOT NULL,
  updated_at VARCHAR(40) NOT NULL,
  PRIMARY KEY (rowid),
  UNIQUE KEY uq_entries_id (id),
  UNIQUE KEY uq_entries_site_url (site_url),
  UNIQUE KEY uq_entries_did (did),
  KEY idx_entries_status (status),
  KEY idx_entries_checked (last_checked_at),
  KEY idx_entries_domain (registrable_domain),
  FULLTEXT KEY fts_entries (display_name, handle, tagline, bio, location, interests_text)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS nonces (
  nonce VARCHAR(64) NOT NULL,
  created_at VARCHAR(40) NOT NULL,
  used_at VARCHAR(40) DEFAULT NULL,
  PRIMARY KEY (nonce),
  KEY idx_nonces_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
  `key` VARCHAR(100) NOT NULL,
  `value` MEDIUMTEXT NOT NULL,
  updated_at VARCHAR(40) NOT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rate_limits (
  ip_key VARCHAR(128) NOT NULL,
  window_start BIGINT NOT NULL,
  hits INT NOT NULL DEFAULT 1,
  PRIMARY KEY (ip_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Versioned schema upgrades applied by Database::migrate (see README, Security & operations).
CREATE TABLE IF NOT EXISTS schema_migrations (
  id VARCHAR(100) NOT NULL,
  applied_at VARCHAR(40) NOT NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

-- New signing keys awaiting operator review (first one seen is kept; conflict = another key appeared later).
CREATE TABLE IF NOT EXISTS key_candidates (
  did VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  public_key_pem TEXT NOT NULL,
  fingerprint CHAR(64) NOT NULL,
  first_seen_at VARCHAR(40) NOT NULL,
  last_seen_at VARCHAR(40) NOT NULL,
  conflict TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (did)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Blocks that outlive a deleted listing, so a blocked site can't re-list itself (security phase 9).
CREATE TABLE IF NOT EXISTS blocked_sites (
  did VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  site_url VARCHAR(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  reason VARCHAR(500) NOT NULL DEFAULT '',
  blocked_at VARCHAR(40) NOT NULL,
  PRIMARY KEY (did),
  KEY idx_blocked_sites_site_url (site_url)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;


-- DID sign-in challenges, HttpOnly user sessions, and per-extension opt-in consents.
CREATE TABLE IF NOT EXISTS auth_challenges (
  id VARCHAR(64) NOT NULL,
  secret_hash CHAR(64) NOT NULL,
  nonce VARCHAR(64) NOT NULL,
  requested_ext VARCHAR(64) NOT NULL DEFAULT '',
  approved_did VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  approved_opt_in_json TEXT DEFAULT NULL,
  expires_at VARCHAR(40) NOT NULL,
  claimed_at VARCHAR(40) DEFAULT NULL,
  created_at VARCHAR(40) NOT NULL,
  PRIMARY KEY (id),
  KEY idx_auth_challenges_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS user_sessions (
  token_hash CHAR(64) NOT NULL,
  did VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  csrf_token VARCHAR(64) NOT NULL,
  created_at VARCHAR(40) NOT NULL,
  last_seen_at VARCHAR(40) NOT NULL,
  expires_at VARCHAR(40) NOT NULL,
  PRIMARY KEY (token_hash),
  KEY idx_user_sessions_did (did),
  KEY idx_user_sessions_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS user_extension_consents (
  did VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  extension_id VARCHAR(64) NOT NULL,
  opted_in TINYINT(1) NOT NULL DEFAULT 0,
  opted_in_at VARCHAR(40) DEFAULT NULL,
  opted_out_at VARCHAR(40) DEFAULT NULL,
  PRIMARY KEY (did, extension_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
