-- Run this once.
-- Lets a client be assigned to one or more warehouses, so they only show
-- up in that warehouse's client dropdown when creating a container -
-- cuts down on picking the wrong client in a multi-location operation.
--
-- Same philosophy as the rest of the warehouse feature: a client with NO
-- assignment here is treated as global - visible to everyone, regardless
-- of warehouse. Nothing changes for any client until you deliberately
-- assign them.

CREATE TABLE IF NOT EXISTS customer_warehouses (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    customer_id    INT NOT NULL,
    warehouse_id   INT NOT NULL,
    UNIQUE KEY uk_customer_warehouse (customer_id, warehouse_id)
);
