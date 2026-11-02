-- Explicit opt-in DDL for a dedicated MySQL/InnoDB replay ledger. Never run on package install.
CREATE TABLE IF NOT EXISTS dpop_proofs (
  security_domain VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  jkt CHAR(43) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  jti_hash BINARY(32) NOT NULL,
  accepted_at BIGINT UNSIGNED NOT NULL,
  expires_at BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (security_domain,jkt,jti_hash),
  KEY expires_at (expires_at)
) ENGINE=InnoDB;
