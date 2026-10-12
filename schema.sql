-- AWIN export schema (MariaDB 10.2+). awin_cron.php runs this on every start; all statements are idempotent.
-- Every table keeps the full API record in `raw` (JSON); the other columns are extracted for querying.

CREATE TABLE IF NOT EXISTS awin_accounts (
    account_id      BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    account_name    VARCHAR(255) NULL,
    account_type    VARCHAR(20)  NULL,
    user_role       VARCHAR(50)  NULL,
    raw             JSON NOT NULL,
    synced_at       DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

CREATE TABLE IF NOT EXISTS awin_programmes (
    publisher_id    BIGINT UNSIGNED NOT NULL,
    advertiser_id   BIGINT UNSIGNED NOT NULL,
    relationship    VARCHAR(20)  NOT NULL,
    name            VARCHAR(255) NULL,
    primary_region  CHAR(2)      NULL,
    currency_code   CHAR(3)      NULL,
    display_url     VARCHAR(500) NULL,
    raw             JSON NOT NULL,
    synced_at       DATETIME NOT NULL,
    PRIMARY KEY (publisher_id, advertiser_id),
    KEY idx_relationship (publisher_id, relationship)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

CREATE TABLE IF NOT EXISTS awin_programme_details (
    publisher_id    BIGINT UNSIGNED NOT NULL,
    advertiser_id   BIGINT UNSIGNED NOT NULL,
    raw             JSON NOT NULL,
    synced_at       DATETIME NOT NULL,
    PRIMARY KEY (publisher_id, advertiser_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

CREATE TABLE IF NOT EXISTS awin_commission_groups (
    publisher_id    BIGINT UNSIGNED NOT NULL,
    advertiser_id   BIGINT UNSIGNED NOT NULL,
    group_key       VARCHAR(100) NOT NULL,
    group_id        BIGINT UNSIGNED NULL,
    group_code      VARCHAR(100) NULL,
    group_name      VARCHAR(255) NULL,
    type            VARCHAR(20)  NULL,
    percentage      DECIMAL(10,4) NULL,
    amount          DECIMAL(14,4) NULL,
    currency        CHAR(3)      NULL,
    raw             JSON NOT NULL,
    synced_at       DATETIME NOT NULL,
    PRIMARY KEY (publisher_id, advertiser_id, group_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

CREATE TABLE IF NOT EXISTS awin_promotions (
    publisher_id    BIGINT UNSIGNED NOT NULL,
    promotion_id    BIGINT UNSIGNED NOT NULL,
    advertiser_id   BIGINT UNSIGNED NULL,
    type            VARCHAR(20)  NULL,
    title           VARCHAR(500) NULL,
    voucher_code    VARCHAR(255) NULL,
    start_date      DATETIME NULL,
    end_date        DATETIME NULL,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,   -- 0 = no longer returned by AWIN as active
    raw             JSON NOT NULL,
    first_seen_at   DATETIME NOT NULL,
    synced_at       DATETIME NOT NULL,
    PRIMARY KEY (publisher_id, promotion_id),
    KEY idx_advertiser (advertiser_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

CREATE TABLE IF NOT EXISTS awin_transactions (
    transaction_id      BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    publisher_id        BIGINT UNSIGNED NOT NULL,
    advertiser_id       BIGINT UNSIGNED NULL,
    commission_status   VARCHAR(20) NULL,
    transaction_date    DATETIME NULL,
    validation_date     DATETIME NULL,
    commission_amount   DECIMAL(14,4) NULL,
    commission_currency CHAR(3) NULL,
    sale_amount         DECIMAL(14,4) NULL,
    sale_currency       CHAR(3) NULL,
    click_ref           VARCHAR(255) NULL,
    publisher_url       VARCHAR(1000) NULL,
    decline_reason      VARCHAR(500) NULL,
    raw                 JSON NOT NULL,
    synced_at           DATETIME NOT NULL,
    KEY idx_pub_date (publisher_id, transaction_date),
    KEY idx_status (commission_status),
    KEY idx_advertiser (advertiser_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

-- Advertiser, creative and campaign performance reports, one row per report line per day.
-- Rows for (publisher, report_type, region, report_date) are replaced on every fetch.
CREATE TABLE IF NOT EXISTS awin_report_rows (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    publisher_id    BIGINT UNSIGNED NOT NULL,
    report_type     VARCHAR(20) NOT NULL,          -- advertiser | creative | campaign
    region          CHAR(2) NOT NULL,
    report_date     DATE NOT NULL,
    advertiser_id   BIGINT UNSIGNED NULL,
    creative_id     BIGINT UNSIGNED NULL,
    campaign        VARCHAR(128) NULL,
    impressions     BIGINT NULL,
    clicks          BIGINT NULL,
    total_no        BIGINT NULL,
    total_value     DECIMAL(14,4) NULL,
    total_comm      DECIMAL(14,4) NULL,
    currency        CHAR(3) NULL,
    raw             JSON NOT NULL,
    synced_at       DATETIME NOT NULL,
    KEY idx_slice (publisher_id, report_type, region, report_date),
    KEY idx_advertiser (advertiser_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

-- Feeds from the feed list with a membership in product_feed_membership (default: active). `raw` holds the feed list row without its URL (the URL contains the datafeed key).
CREATE TABLE IF NOT EXISTS awin_feeds (
    publisher_id      BIGINT UNSIGNED NOT NULL,
    feed_id           VARCHAR(20)  NOT NULL,       -- numeric for Awin-format feeds, "F123" for Google-format feeds
    advertiser_id     BIGINT UNSIGNED NULL,
    advertiser_name   VARCHAR(255) NULL,
    primary_region    CHAR(2)      NULL,
    membership_status VARCHAR(20)  NULL,
    feed_format       VARCHAR(20)  NULL,
    feed_name         VARCHAR(255) NULL,
    language          VARCHAR(50)  NULL,
    vertical          VARCHAR(100) NULL,
    awin_imported_at  DATETIME     NULL,           -- "Last Imported": when AWIN last refreshed the feed
    product_count     INT UNSIGNED NULL,
    imported_version  DATETIME     NULL,           -- awin_imported_at of the version now in awin_products (NULL = not imported)
    imported_rows     INT UNSIGNED NULL,
    imported_at       DATETIME     NULL,
    raw               JSON NOT NULL,
    synced_at         DATETIME NOT NULL,
    PRIMARY KEY (publisher_id, feed_id),
    KEY idx_advertiser (advertiser_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

-- Products from the imported feeds. A feed's rows are replaced when AWIN publishes a new version of it.
CREATE TABLE IF NOT EXISTS awin_products (
    publisher_id        BIGINT UNSIGNED NOT NULL,
    feed_id             VARCHAR(20)  NOT NULL,
    product_key         VARCHAR(100) NOT NULL,     -- aw_product_id (Awin format) or id (Google format)
    advertiser_id       BIGINT UNSIGNED NULL,
    merchant_product_id VARCHAR(255) NULL,
    name                VARCHAR(500) NULL,
    brand               VARCHAR(255) NULL,
    ean                 VARCHAR(50)  NULL,
    mpn                 VARCHAR(100) NULL,
    category            VARCHAR(500) NULL,
    price               DECIMAL(14,4) NULL,
    old_price           DECIMAL(14,4) NULL,
    delivery_cost       DECIMAL(14,4) NULL,
    currency            CHAR(3)      NULL,
    in_stock            TINYINT(1)   NULL,
    deep_link           TEXT NULL,                 -- tracking link
    image_url           TEXT NULL,
    last_updated        DATETIME NULL,
    raw                 JSON NOT NULL,             -- the full feed row (empty fields left out)
    synced_at           DATETIME NOT NULL,
    PRIMARY KEY (publisher_id, feed_id, product_key),
    KEY idx_advertiser (advertiser_id),
    KEY idx_ean (ean)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

CREATE TABLE IF NOT EXISTS awin_sync_state (
    publisher_id    BIGINT UNSIGNED NOT NULL,
    part            VARCHAR(30) NOT NULL,
    last_run_at     DATETIME NOT NULL,
    PRIMARY KEY (publisher_id, part)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;
