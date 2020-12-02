DROP TABLE IF EXISTS Cluster;
CREATE TABLE Cluster (
	ClusterID INT NOT NULL PRIMARY KEY,
	EffectTypeID INT NOT NULL,
	LongName VARCHAR(50) NOT NULL,
	NPReq INT NOT NULL,
	SkillID INT NOT NULL
);
INSERT INTO Cluster (ClusterID, EffectTypeID, LongName, NPReq, SkillID) VALUES
(0,1,'',0,''),
(2,1,'1 Handed Blunt Weapons',720,102),
(3,1,'1 Handed Edged Weapon',760,103),
(4,1,'2 Handed Blunt Weapons',720,107),
(5,1,'2 Handed Edged Weapons',760,105),
(6,1,'Adventuring',600,137),
(7,2,'Agility',900,17),
(8,1,'Aimed Shot',840,151),
(9,1,'Assault Rifle',900,116),
(10,16,'Biological Metamorphosis',960,128),
(11,1,'Body Development',800,152),
(12,1,'Bow',800,111),
(13,1,'Bow Special Attack',800,121),
(14,1,'Brawling',660,142),
(15,1,'Breaking and Entry',800,165),
(16,1,'Burst',840,148),
(17,3,'Chemical AC',800,93),
(18,1,'Chemistry',800,163),
(19,3,'Cold AC',800,95),
(20,1,'Computer Literacy',800,161),
(21,1,'Concealment',720,164),
(22,1,'Dimach',900,144),
(24,1,'Dodge Ranged Attacks',800,154),
(25,1,'Duck Explosives',800,153),
(26,1,'Electrical Engineering',800,126),
(27,3,'Energy AC',900,92),
(28,1,'Evade Close Combat',800,155),
(29,1,'Fast Attack',760,147),
(30,3,'Fire AC',800,97),
(31,1,'First Aid',720,123),
(32,1,'Fling Shot',720,150),
(33,1,'Full Auto',900,167),
(34,1,'Grenade Throwing',760,109),
(35,1,'Heavy Weapons',400,110),
(36,3,'Projectile AC',900,90),
(37,2,'Intelligence',900,19),
(38,1,'Map Navigation',500,140),
(39,1,'Martial Arts',1000,100),
(40,16,'Matter Creation',960,130),
(41,16,'Matter Metamorphosis',960,127),
(42,4,'Max Health',1000,1),
(43,4,'Max Nano',1000,221),
(44,1,'Mechanical Engineering',800,125),
(45,1,'Melee Energy Weapons',800,104),
(46,1,'Melee Weapons Initiative',800,118),
(47,3,'Melee AC',900,91),
(48,1,'MG/SMG',800,114),
(49,1,'Multiple Melee Weapons',900,101),
(50,1,'Multiple Ranged Weapons',800,134),
(51,1,'Nano Initiative',800,149),
(52,1,'Nano Pool',1200,132),
(53,1,'Nano Programming',800,160),
(54,1,'Nano Resistance',800,168),
(55,1,'Deflect',840,145),
(56,1,'Perception',800,136),
(57,1,'Pharmaceuticals',800,159),
(58,1,'Physical Initiative',800,120),
(59,1,'Piercing',640,106),
(60,1,'Pistol',800,112),
(61,2,'Psychic',900,21),
(62,16,'Psychological Modifications',960,129),
(63,1,'Psychology',800,162),
(64,1,'Quantum Physics',1000,157),
(65,3,'Radiation AC',800,94),
(66,1,'Ranged Energy',800,133),
(67,1,'Ranged Initiative',800,119),
(68,1,'Rifle',900,113),
(69,1,'Riposte',1000,143),
(70,1,'Run Speed',1000,156),
(71,2,'Sense',900,20),
(72,16,'Sensory Improvement',880,122),
(73,1,'Sharp Objects',500,108),
(74,1,'Shotgun',680,115),
(75,1,'Sneak Attack',1000,146),
(76,2,'Stamina',900,18),
(77,2,'Strength',900,16),
(78,1,'Swimming',500,138),
(79,16,'Time and Space',960,131),
(80,1,'Trap Disarming',720,135),
(81,1,'Treatment',860,124),
(82,1,'Tutoring',520,141),
(83,1,'Vehicle Air',400,139),
(84,1,'Vehicle Ground',600,166),
(85,1,'Vehicle Water',480,117),
(86,1,'Weapon Smithing',800,158),
(87,15,'Nano Delta*',1,364),
(88,15,'Heal Delta*',1,343),
(89,8,'Add All Defense*',1,277),
(90,9,'Add All Offense*',1,276),
(91,10,'Add Max NCU*',1,181),
(92,5,'Add XP (%)*',1,319),
(93,12,'Nano Interrupt (%)*',1,383),
(94,6,'Add Chemical Damage*',1,281),
(95,6,'Add Energy Damage*',1,280),
(96,6,'Add Fire Damage*',1,316),
(97,6,'Add Melee Damage*',1,279),
(98,6,'Add Poison Damage*',1,317),
(99,6,'Add Projectile Damage*',1,278),
(100,6,'Add Radiation Damage*',1,282),
(101,7,'Chemical Damage Shield*',1,229),
(102,7,'Cold Damage Shield*',1,231),
(103,7,'Energy Damage Shield*',1,228),
(104,7,'Fire Damage Shield*',1,233),
(105,7,'Melee Damage Shield*',1,227),
(106,7,'Poison Damage Shield*',1,234),
(107,7,'Projectile Damage Shield*',1,226),
(108,7,'Radiation Damage Shield*',1,230),
(109,11,'Skill Lock (%)*',1,382),
(110,13,'Nano Cost (%)*',1,318),
(111,14,'Add Nano Range (%)*',1,381),
(112,3,'Disease AC',800,96),
(130,14,'Add Weapon Range (%)*',1,380);
