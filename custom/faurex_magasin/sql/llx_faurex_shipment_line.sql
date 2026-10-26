CREATE TABLE IF NOT EXISTS llx_faurex_shipment_line (
  rowid           INT AUTO_INCREMENT PRIMARY KEY,
  fk_shipment     INT NOT NULL,
  fk_facturedet   INT NOT NULL,
  fk_product      INT,
  qty_invoiced    DOUBLE NOT NULL DEFAULT 0,
  qty_delivered   DOUBLE NOT NULL DEFAULT 0,
  fk_warehouse    INT DEFAULT NULL,
  date_shipment   DATE,
  mode_reglement  VARCHAR(64),
  account_credit  VARCHAR(64),
  date_creation   DATETIME,
  fk_user_creat   INT,
  KEY idx_faurex_shipment_line_ship (fk_shipment),
  KEY idx_faurex_shipment_line_fdet (fk_facturedet),
  KEY idx_faurex_shipment_line_ship_fdet (fk_shipment, fk_facturedet)
) ENGINE=InnoDB;
