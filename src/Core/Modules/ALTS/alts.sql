CREATE TABLE IF NOT EXISTS alts (
	`alt` VARCHAR(25) NOT NULL PRIMARY KEY,
	`main` VARCHAR(25),
	`validated` TINYINT(1) DEFAULT 0
);

INSERT INTO alts (alt, main, validated) VALUES ('Pigtail', 'Nadyita', 1);