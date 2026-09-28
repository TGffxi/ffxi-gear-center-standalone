addon.name = 'GearplanerBridge';
addon.author = 'Gearplaner project';
addon.version = '0.6.0';
addon.desc = 'Exports unified FFXI inventory, equipment, currency and progression state for Gearplaner.';
addon.link = '';

require 'common';
local chat = require('chat');
local struct = require('struct');

local BRIDGE_SCHEMA = 'gearplaner.character-state/0.6';
local EXPORT_INTERVAL = 15.0;
local lastExportClock = 0;
local exportQueued = false;
local lastCurrencyPacket113 = nil;
local lastCurrencyPacket118 = nil;
local lastConquestPacket05E = nil;
local exportSequence = 0;

local containerNames = {
    [0]  = 'Inventory',
    [1]  = 'Mog Safe',
    [2]  = 'Storage',
    [3]  = 'Temporary',
    [4]  = 'Mog Locker',
    [5]  = 'Mog Satchel',
    [6]  = 'Mog Sack',
    [7]  = 'Mog Case',
    [8]  = 'Wardrobe 1',
    [9]  = 'Mog Safe 2',
    [10] = 'Wardrobe 2',
    [11] = 'Wardrobe 3',
    [12] = 'Wardrobe 4',
    [13] = 'Wardrobe 5',
    [14] = 'Wardrobe 6',
    [15] = 'Wardrobe 7',
    [16] = 'Wardrobe 8',
};

-- Semantic currency maps verified against Windower/Lua packets/fields.lua (dev, 2026-09-01).
-- Windower documents zero-based packet offsets; Ashita's lstruct uses +1 indexing.
-- Unknown/padding fields are deliberately omitted.
local currencyFields113 = {
    { key = 'conquest_sandoria', label = 'Conquest Points (San d\'Oria)', fmt = 'i', offset = 0x5 },
    { key = 'conquest_bastok', label = 'Conquest Points (Bastok)', fmt = 'i', offset = 0x9 },
    { key = 'conquest_windurst', label = 'Conquest Points (Windurst)', fmt = 'i', offset = 0xD },
    { key = 'beastmen_seals', label = 'Beastman Seals', fmt = 'H', offset = 0x11 },
    { key = 'kindred_seals', label = 'Kindred Seals', fmt = 'H', offset = 0x13 },
    { key = 'kindred_crests', label = 'Kindred Crests', fmt = 'H', offset = 0x15 },
    { key = 'high_kindred_crests', label = 'High Kindred Crests', fmt = 'H', offset = 0x17 },
    { key = 'sacred_kindred_crests', label = 'Sacred Kindred Crests', fmt = 'H', offset = 0x19 },
    { key = 'ancient_beastcoins', label = 'Ancient Beastcoins', fmt = 'H', offset = 0x1B },
    { key = 'valor_points', label = 'Valor Points', fmt = 'H', offset = 0x1D },
    { key = 'scylds', label = 'Scylds', fmt = 'H', offset = 0x1F },
    { key = 'guild_points_fishing', label = 'Guild Points (Fishing)', fmt = 'i', offset = 0x21 },
    { key = 'guild_points_woodworking', label = 'Guild Points (Woodworking)', fmt = 'i', offset = 0x25 },
    { key = 'guild_points_smithing', label = 'Guild Points (Smithing)', fmt = 'i', offset = 0x29 },
    { key = 'guild_points_goldsmithing', label = 'Guild Points (Goldsmithing)', fmt = 'i', offset = 0x2D },
    { key = 'guild_points_weaving', label = 'Guild Points (Weaving)', fmt = 'i', offset = 0x31 },
    { key = 'guild_points_leathercraft', label = 'Guild Points (Leathercraft)', fmt = 'i', offset = 0x35 },
    { key = 'guild_points_bonecraft', label = 'Guild Points (Bonecraft)', fmt = 'i', offset = 0x39 },
    { key = 'guild_points_alchemy', label = 'Guild Points (Alchemy)', fmt = 'i', offset = 0x3D },
    { key = 'guild_points_cooking', label = 'Guild Points (Cooking)', fmt = 'i', offset = 0x41 },
    { key = 'cinders', label = 'Cinders', fmt = 'i', offset = 0x45 },
    { key = 'synergy_fewell_fire', label = 'Synergy Fewell (Fire)', fmt = 'B', offset = 0x49 },
    { key = 'synergy_fewell_ice', label = 'Synergy Fewell (Ice)', fmt = 'B', offset = 0x4A },
    { key = 'synergy_fewell_wind', label = 'Synergy Fewell (Wind)', fmt = 'B', offset = 0x4B },
    { key = 'synergy_fewell_earth', label = 'Synergy Fewell (Earth)', fmt = 'B', offset = 0x4C },
    { key = 'synergy_fewell_lightning', label = 'Synergy Fewell (Lightning)', fmt = 'B', offset = 0x4D },
    { key = 'synergy_fewell_water', label = 'Synergy Fewell (Water)', fmt = 'B', offset = 0x4E },
    { key = 'synergy_fewell_light', label = 'Synergy Fewell (Light)', fmt = 'B', offset = 0x4F },
    { key = 'synergy_fewell_dark', label = 'Synergy Fewell (Dark)', fmt = 'B', offset = 0x50 },
    { key = 'ballista_points', label = 'Ballista Points', fmt = 'i', offset = 0x51 },
    { key = 'fellow_points', label = 'Fellow Points', fmt = 'i', offset = 0x55 },
    { key = 'chocobucks_sandoria', label = 'Chocobucks (San d\'Oria)', fmt = 'H', offset = 0x59 },
    { key = 'chocobucks_bastok', label = 'Chocobucks (Bastok)', fmt = 'H', offset = 0x5B },
    { key = 'chocobucks_windurst', label = 'Chocobucks (Windurst)', fmt = 'H', offset = 0x5D },
    { key = 'daily_tally', label = 'Daily Tally', fmt = 'H', offset = 0x5F },
    { key = 'research_marks', label = 'Research Marks', fmt = 'i', offset = 0x61 },
    { key = 'wizened_tunnel_worms', label = 'Wizened Tunnel Worms', fmt = 'B', offset = 0x65 },
    { key = 'wizened_morion_worms', label = 'Wizened Morion Worms', fmt = 'B', offset = 0x66 },
    { key = 'wizened_phantom_worms', label = 'Wizened Phantom Worms', fmt = 'B', offset = 0x67 },
    { key = 'moblin_marbles', label = 'Moblin Marbles', fmt = 'i', offset = 0x69 },
    { key = 'infamy', label = 'Infamy', fmt = 'H', offset = 0x6D },
    { key = 'prestige', label = 'Prestige', fmt = 'H', offset = 0x6F },
    { key = 'legion_points', label = 'Legion Points', fmt = 'i', offset = 0x71 },
    { key = 'sparks', label = 'Sparks of Eminence', fmt = 'i', offset = 0x75 },
    { key = 'shining_stars', label = 'Shining Stars', fmt = 'i', offset = 0x79 },
    { key = 'imperial_standing', label = 'Imperial Standing', fmt = 'i', offset = 0x7D },
    { key = 'assault_points_leujaoam', label = 'Assault Points (Leujaoam Sanctum)', fmt = 'i', offset = 0x81 },
    { key = 'assault_points_mjtg', label = 'Assault Points (Mamool Ja Training Grounds)', fmt = 'i', offset = 0x85 },
    { key = 'assault_points_lebros', label = 'Assault Points (Lebros Cavern)', fmt = 'i', offset = 0x89 },
    { key = 'assault_points_periqia', label = 'Assault Points (Periqia)', fmt = 'i', offset = 0x8D },
    { key = 'assault_points_ilrusi', label = 'Assault Points (Ilrusi Atoll)', fmt = 'i', offset = 0x91 },
    { key = 'nyzul_tokens', label = 'Nyzul Tokens', fmt = 'i', offset = 0x95 },
    { key = 'zeni', label = 'Zeni', fmt = 'i', offset = 0x99 },
    { key = 'jettons', label = 'Jettons', fmt = 'i', offset = 0x9D },
    { key = 'therion_ichor', label = 'Therion Ichor', fmt = 'i', offset = 0xA1 },
    { key = 'allied_notes', label = 'Allied Notes', fmt = 'i', offset = 0xA5 },
    { key = 'aman_vouchers_stored', label = 'A.M.A.N. Vouchers Stored', fmt = 'H', offset = 0xA9 },
    { key = 'login_points', label = 'Login Points', fmt = 'H', offset = 0xAB },
    { key = 'cruor', label = 'Cruor', fmt = 'i', offset = 0xAD },
    { key = 'resistance_credits', label = 'Resistance Credits', fmt = 'i', offset = 0xB1 },
    { key = 'dominion_notes', label = 'Dominion Notes', fmt = 'i', offset = 0xB5 },
    { key = 'battle_trophies_5th_echelon', label = '5th Echelon Battle Trophies', fmt = 'B', offset = 0xB9 },
    { key = 'battle_trophies_4th_echelon', label = '4th Echelon Battle Trophies', fmt = 'B', offset = 0xBA },
    { key = 'battle_trophies_3rd_echelon', label = '3rd Echelon Battle Trophies', fmt = 'B', offset = 0xBB },
    { key = 'battle_trophies_2nd_echelon', label = '2nd Echelon Battle Trophies', fmt = 'B', offset = 0xBC },
    { key = 'battle_trophies_1st_echelon', label = '1st Echelon Battle Trophies', fmt = 'B', offset = 0xBD },
    { key = 'cave_conservation_points', label = 'Cave Conservation Points', fmt = 'B', offset = 0xBE },
    { key = 'imperial_army_id_tags', label = 'Imperial Army ID Tags', fmt = 'B', offset = 0xBF },
    { key = 'op_credits', label = 'Op Credits', fmt = 'B', offset = 0xC0 },
    { key = 'traverser_stones', label = 'Traverser Stones', fmt = 'i', offset = 0xC1 },
    { key = 'voidstones', label = 'Voidstones', fmt = 'i', offset = 0xC5 },
    { key = 'kupofrieds_corundums', label = 'Kupofried\'s Corundums', fmt = 'i', offset = 0xC9 },
    { key = 'moblin_pheromone_sacks', label = 'Moblin Pheromone Sacks', fmt = 'B', offset = 0xCD },
    { key = 'rems_tale_chapter_1', label = 'Rem\'s Tale Chapter 1', fmt = 'B', offset = 0xCF },
    { key = 'rems_tale_chapter_2', label = 'Rem\'s Tale Chapter 2', fmt = 'B', offset = 0xD0 },
    { key = 'rems_tale_chapter_3', label = 'Rem\'s Tale Chapter 3', fmt = 'B', offset = 0xD1 },
    { key = 'rems_tale_chapter_4', label = 'Rem\'s Tale Chapter 4', fmt = 'B', offset = 0xD2 },
    { key = 'rems_tale_chapter_5', label = 'Rem\'s Tale Chapter 5', fmt = 'B', offset = 0xD3 },
    { key = 'rems_tale_chapter_6', label = 'Rem\'s Tale Chapter 6', fmt = 'B', offset = 0xD4 },
    { key = 'rems_tale_chapter_7', label = 'Rem\'s Tale Chapter 7', fmt = 'B', offset = 0xD5 },
    { key = 'rems_tale_chapter_8', label = 'Rem\'s Tale Chapter 8', fmt = 'B', offset = 0xD6 },
    { key = 'rems_tale_chapter_9', label = 'Rem\'s Tale Chapter 9', fmt = 'B', offset = 0xD7 },
    { key = 'rems_tale_chapter_10', label = 'Rem\'s Tale Chapter 10', fmt = 'B', offset = 0xD8 },
    { key = 'reclamation_marks', label = 'Reclamation Marks', fmt = 'i', offset = 0xE1 },
    { key = 'accolades', label = 'Unity Accolades', fmt = 'i', offset = 0xE5 },
    { key = 'fire_crystals', label = 'Fire Crystals', fmt = 'H', offset = 0xE9 },
    { key = 'ice_crystals', label = 'Ice Crystals', fmt = 'H', offset = 0xEB },
    { key = 'wind_crystals', label = 'Wind Crystals', fmt = 'H', offset = 0xED },
    { key = 'earth_crystals', label = 'Earth Crystals', fmt = 'H', offset = 0xEF },
    { key = 'lightning_crystals', label = 'Lightning Crystals', fmt = 'H', offset = 0xF1 },
    { key = 'water_crystals', label = 'Water Crystals', fmt = 'H', offset = 0xF3 },
    { key = 'light_crystals', label = 'Light Crystals', fmt = 'H', offset = 0xF5 },
    { key = 'dark_crystals', label = 'Dark Crystals', fmt = 'H', offset = 0xF7 },
    { key = 'deeds', label = 'Deeds', fmt = 'i', offset = 0xF9 },
};

local currencyFields118 = {
    { key = 'bayld', label = 'Bayld', fmt = 'i', offset = 0x5 },
    { key = 'kinetic_units', label = 'Kinetic Units', fmt = 'H', offset = 0x9 },
    { key = 'coalition_imprimaturs', label = 'Coalition Imprimaturs', fmt = 'B', offset = 0xB },
    { key = 'mystical_canteens', label = 'Mystical Canteens', fmt = 'B', offset = 0xC },
    { key = 'obsidian_fragments', label = 'Obsidian Fragments', fmt = 'i', offset = 0xD },
    { key = 'lebondopt_wings_stored', label = 'Lebondopt Wings Stored', fmt = 'H', offset = 0x11 },
    { key = 'pulchridopt_wings_stored', label = 'Pulchridopt Wings Stored', fmt = 'H', offset = 0x13 },
    { key = 'mweya_plasm_corpuscles', label = 'Mweya Plasm Corpuscles', fmt = 'i', offset = 0x15 },
    { key = 'ghastly_stones', label = 'Ghastly Stones Stored', fmt = 'B', offset = 0x19 },
    { key = 'ghastly_stones_1', label = 'Ghastly Stones +1 Stored', fmt = 'B', offset = 0x1A },
    { key = 'ghastly_stones_2', label = 'Ghastly Stones +2 Stored', fmt = 'B', offset = 0x1B },
    { key = 'verdigris_stones', label = 'Verdigris Stones Stored', fmt = 'B', offset = 0x1C },
    { key = 'verdigris_stones_1', label = 'Verdigris Stones +1 Stored', fmt = 'B', offset = 0x1D },
    { key = 'verdigris_stones_2', label = 'Verdigris Stones +2 Stored', fmt = 'B', offset = 0x1E },
    { key = 'wailing_stones', label = 'Wailing Stones Stored', fmt = 'B', offset = 0x1F },
    { key = 'wailing_stones_1', label = 'Wailing Stones +1 Stored', fmt = 'B', offset = 0x20 },
    { key = 'wailing_stones_2', label = 'Wailing Stones +2 Stored', fmt = 'B', offset = 0x21 },
    { key = 'snowslit_stones', label = 'Snowslit Stones Stored', fmt = 'B', offset = 0x22 },
    { key = 'snowslit_stones_1', label = 'Snowslit Stones +1 Stored', fmt = 'B', offset = 0x23 },
    { key = 'snowslit_stones_2', label = 'Snowslit Stones +2 Stored', fmt = 'B', offset = 0x24 },
    { key = 'snowtip_stones', label = 'Snowtip Stones Stored', fmt = 'B', offset = 0x25 },
    { key = 'snowtip_stones_1', label = 'Snowtip Stones +1 Stored', fmt = 'B', offset = 0x26 },
    { key = 'snowtip_stones_2', label = 'Snowtip Stones +2 Stored', fmt = 'B', offset = 0x27 },
    { key = 'snowdim_stones', label = 'Snowdim Stones Stored', fmt = 'B', offset = 0x28 },
    { key = 'snowdim_stones_1', label = 'Snowdim Stones +1 Stored', fmt = 'B', offset = 0x29 },
    { key = 'snowdim_stones_2', label = 'Snowdim Stones +2 Stored', fmt = 'B', offset = 0x2A },
    { key = 'snoworb_stones', label = 'Snoworb Stones Stored', fmt = 'B', offset = 0x2B },
    { key = 'snoworb_stones_1', label = 'Snoworb Stones +1 Stored', fmt = 'B', offset = 0x2C },
    { key = 'snoworb_stones_2', label = 'Snoworb Stones +2 Stored', fmt = 'B', offset = 0x2D },
    { key = 'leafslit_stones', label = 'Leafslit Stones Stored', fmt = 'B', offset = 0x2E },
    { key = 'leafslit_stones_1', label = 'Leafslit Stones +1 Stored', fmt = 'B', offset = 0x2F },
    { key = 'leafslit_stones_2', label = 'Leafslit Stones +2 Stored', fmt = 'B', offset = 0x30 },
    { key = 'leaftip_stones', label = 'Leaftip Stones Stored', fmt = 'B', offset = 0x31 },
    { key = 'leaftip_stones_1', label = 'Leaftip Stones +1 Stored', fmt = 'B', offset = 0x32 },
    { key = 'leaftip_stones_2', label = 'Leaftip Stones +2 Stored', fmt = 'B', offset = 0x33 },
    { key = 'leafdim_stones', label = 'Leafdim Stones Stored', fmt = 'B', offset = 0x34 },
    { key = 'leafdim_stones_1', label = 'Leafdim Stones +1 Stored', fmt = 'B', offset = 0x35 },
    { key = 'leafdim_stones_2', label = 'Leafdim Stones +2 Stored', fmt = 'B', offset = 0x36 },
    { key = 'leaforb_stones', label = 'Leaforb Stones Stored', fmt = 'B', offset = 0x37 },
    { key = 'leaforb_stones_1', label = 'Leaforb Stones +1 Stored', fmt = 'B', offset = 0x38 },
    { key = 'leaforb_stones_2', label = 'Leaforb Stones +2 Stored', fmt = 'B', offset = 0x39 },
    { key = 'duskslit_stones', label = 'Duskslit Stones Stored', fmt = 'B', offset = 0x3A },
    { key = 'duskslit_stones_1', label = 'Duskslit Stones +1 Stored', fmt = 'B', offset = 0x3B },
    { key = 'duskslit_stones_2', label = 'Duskslit Stones +2 Stored', fmt = 'B', offset = 0x3C },
    { key = 'dusktip_stones', label = 'Dusktip Stones Stored', fmt = 'B', offset = 0x3D },
    { key = 'dusktip_stones_1', label = 'Dusktip Stones +1 Stored', fmt = 'B', offset = 0x3E },
    { key = 'dusktip_stones_2', label = 'Dusktip Stones +2 Stored', fmt = 'B', offset = 0x3F },
    { key = 'duskdim_stones', label = 'Duskdim Stones Stored', fmt = 'B', offset = 0x40 },
    { key = 'duskdim_stones_1', label = 'Duskdim Stones +1 Stored', fmt = 'B', offset = 0x41 },
    { key = 'duskdim_stones_2', label = 'Duskdim Stones +2 Stored', fmt = 'B', offset = 0x42 },
    { key = 'duskorb_stones', label = 'Duskorb Stones Stored', fmt = 'B', offset = 0x43 },
    { key = 'duskorb_stones_1', label = 'Duskorb Stones +1 Stored', fmt = 'B', offset = 0x44 },
    { key = 'duskorb_stones_2', label = 'Duskorb Stones +2 Stored', fmt = 'B', offset = 0x45 },
    { key = 'pellucid_stones', label = 'Pellucid Stones Stored', fmt = 'B', offset = 0x46 },
    { key = 'fern_stones', label = 'Fern Stones Stored', fmt = 'B', offset = 0x47 },
    { key = 'taupe_stones', label = 'Taupe Stones Stored', fmt = 'B', offset = 0x48 },
    { key = 'mellidopt_wings_stored', label = 'Mellidopt Wings Stored', fmt = 'H', offset = 0x49 },
    { key = 'escha_beads', label = 'Escha Beads', fmt = 'H', offset = 0x4B },
    { key = 'escha_silt', label = 'Escha Silt', fmt = 'i', offset = 0x4D },
    { key = 'potpourri', label = 'Potpourri', fmt = 'i', offset = 0x51 },
    { key = 'hallmarks', label = 'Hallmarks', fmt = 'i', offset = 0x55 },
    { key = 'total_hallmarks', label = 'Total Hallmarks', fmt = 'i', offset = 0x59 },
    { key = 'badges_of_gallantry', label = 'Badges of Gallantry', fmt = 'i', offset = 0x5D },
    { key = 'crafter_points', label = 'Crafter Points', fmt = 'i', offset = 0x61 },
    { key = 'fire_crystals_set', label = 'Fire Crystals Set', fmt = 'B', offset = 0x65 },
    { key = 'ice_crystals_set', label = 'Ice Crystals Set', fmt = 'B', offset = 0x66 },
    { key = 'wind_crystals_set', label = 'Wind Crystals Set', fmt = 'B', offset = 0x67 },
    { key = 'earth_crystals_set', label = 'Earth Crystals Set', fmt = 'B', offset = 0x68 },
    { key = 'lightning_crystals_set', label = 'Lightning Crystals Set', fmt = 'B', offset = 0x69 },
    { key = 'water_crystals_set', label = 'Water Crystals Set', fmt = 'B', offset = 0x6A },
    { key = 'light_crystals_set', label = 'Light Crystals Set', fmt = 'B', offset = 0x6B },
    { key = 'dark_crystals_set', label = 'Dark Crystals Set', fmt = 'B', offset = 0x6C },
    { key = 'mc_s_sr01s_set', label = 'MC-S-SR01s Set', fmt = 'B', offset = 0x6D },
    { key = 'mc_s_sr02s_set', label = 'MC-S-SR02s Set', fmt = 'B', offset = 0x6E },
    { key = 'mc_s_sr03s_set', label = 'MC-S-SR03s Set', fmt = 'B', offset = 0x6F },
    { key = 'liquefaction_spheres_set', label = 'Liquefaction Spheres Set', fmt = 'B', offset = 0x70 },
    { key = 'induration_spheres_set', label = 'Induration Spheres Set', fmt = 'B', offset = 0x71 },
    { key = 'detonation_spheres_set', label = 'Detonation Spheres Set', fmt = 'B', offset = 0x72 },
    { key = 'scission_spheres_set', label = 'Scission Spheres Set', fmt = 'B', offset = 0x73 },
    { key = 'impaction_spheres_set', label = 'Impaction Spheres Set', fmt = 'B', offset = 0x74 },
    { key = 'reverberation_spheres_set', label = 'Reverberation Spheres Set', fmt = 'B', offset = 0x75 },
    { key = 'transfixion_spheres_set', label = 'Transfixion Spheres Set', fmt = 'B', offset = 0x76 },
    { key = 'compression_spheres_set', label = 'Compression Spheres Set', fmt = 'B', offset = 0x77 },
    { key = 'fusion_spheres_set', label = 'Fusion Spheres Set', fmt = 'B', offset = 0x78 },
    { key = 'distortion_spheres_set', label = 'Distortion Spheres Set', fmt = 'B', offset = 0x79 },
    { key = 'fragmentation_spheres_set', label = 'Fragmentation Spheres Set', fmt = 'B', offset = 0x7A },
    { key = 'gravitation_spheres_set', label = 'Gravitation Spheres Set', fmt = 'B', offset = 0x7B },
    { key = 'light_spheres_set', label = 'Light Spheres Set', fmt = 'B', offset = 0x7C },
    { key = 'darkness_spheres_set', label = 'Darkness Spheres Set', fmt = 'B', offset = 0x7D },
    { key = 'silver_aman_vouchers_stored', label = 'Silver A.M.A.N. Vouchers Stored', fmt = 'i', offset = 0x81 },
    { key = 'domain_points', label = 'Domain Points', fmt = 'i', offset = 0x85 },
    { key = 'domain_points_earned_today', label = 'Domain Points Earned Today', fmt = 'i', offset = 0x89 },
    { key = 'mog_segments', label = 'Mog Segments', fmt = 'i', offset = 0x8D },
    { key = 'gallimaufry', label = 'Gallimaufry', fmt = 'i', offset = 0x91 },
    { key = 'imperial_standing_accolades', label = 'Imperial Standing Accolades', fmt = 'i', offset = 0x95 },
    { key = 'temenos_units', label = 'Temenos Units', fmt = 'i', offset = 0x99 },
    { key = 'apollyon_units', label = 'Apollyon Units', fmt = 'i', offset = 0x9D },
};

local equipSlotNames = {
    [0] = 'main', [1] = 'sub', [2] = 'range', [3] = 'ammo',
    [4] = 'head', [5] = 'body', [6] = 'hands', [7] = 'legs', [8] = 'feet',
    [9] = 'neck', [10] = 'waist', [11] = 'ear1', [12] = 'ear2',
    [13] = 'ring1', [14] = 'ring2', [15] = 'back',
};

local function json_escape(s)
    s = tostring(s or '');
    s = s:gsub('\\', '\\\\')
         :gsub('"', '\\"')
         :gsub('\b', '\\b')
         :gsub('\f', '\\f')
         :gsub('\n', '\\n')
         :gsub('\r', '\\r')
         :gsub('\t', '\\t');
    s = s:gsub('[%z\1-\31]', function(c)
        return string.format('\\u%04x', string.byte(c));
    end);
    return '"' .. s .. '"';
end

local function is_array(t)
    if type(t) ~= 'table' then return false, 0; end
    local n = 0;
    for k, _ in pairs(t) do
        if type(k) ~= 'number' or k < 1 or k % 1 ~= 0 then return false, 0; end
        if k > n then n = k; end
    end
    for i = 1, n do
        if t[i] == nil then return false, 0; end
    end
    return true, n;
end

local function json_encode(v)
    local tv = type(v);
    if v == nil then return 'null'; end
    if tv == 'boolean' then return v and 'true' or 'false'; end
    if tv == 'number' then
        if v ~= v or v == math.huge or v == -math.huge then return 'null'; end
        return tostring(v);
    end
    if tv == 'string' then return json_escape(v); end
    if tv ~= 'table' then return json_escape(tostring(v)); end

    local arr, n = is_array(v);
    local out = {};
    if arr then
        for i = 1, n do out[#out + 1] = json_encode(v[i]); end
        return '[' .. table.concat(out, ',') .. ']';
    end

    for k, value in pairs(v) do
        out[#out + 1] = json_escape(k) .. ':' .. json_encode(value);
    end
    table.sort(out);
    return '{' .. table.concat(out, ',') .. '}';
end

local function bytes_to_hex(data, maxBytes)
    if data == nil then return nil; end
    local ok, len = pcall(function() return #data; end);
    if not ok or not len or len <= 0 then return nil; end
    if maxBytes and len > maxBytes then len = maxBytes; end
    local out = {};
    for i = 1, len do
        local b = string.byte(data, i);
        if b == nil then break; end
        out[#out + 1] = string.format('%02X', b);
    end
    return table.concat(out);
end

local function item_extra_hex(item)
    if item == nil then return nil; end
    local okExtra, extra = pcall(function() return item.Extra; end);
    if not okExtra or extra == nil then return nil; end
    local out = {};
    for i = 0, 23 do
        local ok, b = pcall(function() return tonumber(extra[i]); end);
        if not ok or b == nil then break; end
        out[#out + 1] = string.format('%02X', b % 256);
    end
    if #out == 0 then return nil; end
    return table.concat(out);
end

local function safe_call(obj, method, ...)
    if obj == nil then return nil; end
    local okFn, fn = pcall(function() return obj[method]; end);
    if not okFn or type(fn) ~= 'function' then return nil; end
    local args = {...};
    local ok, value = pcall(function() return fn(obj, unpack(args)); end);
    if ok then return value; end
    return nil;
end

local function player_name()
    local mm = AshitaCore:GetMemoryManager();
    if mm == nil then return nil; end
    local party = mm:GetParty();
    local entity = mm:GetEntity();
    if party == nil or entity == nil then return nil; end
    local idx = party:GetMemberTargetIndex(0);
    if idx == nil or idx == 0 then return nil; end
    local n = entity:GetName(idx);
    if n == nil or n == '' then return nil; end
    return n;
end

local function build_equipped_lookup(inv)
    local lookup = {};
    local equipped = {};
    for eqslot = 0, 15 do
        local e = inv:GetEquippedItem(eqslot);
        if e ~= nil and e.Index ~= nil and e.Index ~= 0 then
            local containerId = bit.band(e.Index, 0xFF00) / 0x0100;
            local slot = e.Index % 0x0100;
            local key = tostring(containerId) .. ':' .. tostring(slot);
            lookup[key] = lookup[key] or {};
            table.insert(lookup[key], equipSlotNames[eqslot] or tostring(eqslot));
            local physical = inv:GetContainerItem(containerId, slot);
            equipped[#equipped + 1] = {
                equip_slot = eqslot,
                equip_slot_name = equipSlotNames[eqslot],
                container_id = containerId,
                container_name = containerNames[containerId],
                slot = slot,
                item_id = physical and physical.Id or nil,
            };
        end
    end
    return lookup, equipped;
end

local function build_inventory()
    local inv = AshitaCore:GetMemoryManager():GetInventory();
    if inv == nil then return {}, {}, {}; end
    local resMgr = AshitaCore:GetResourceManager();
    local equippedLookup, equipped = build_equipped_lookup(inv);
    local items = {};
    local containers = {};

    -- Slot 0 of Inventory is the gil pseudo-item, not a normal item slot.
    for containerId = 0, 16 do
        local maxCount = inv:GetContainerCountMax(containerId) or 0;
        local usedCount = inv:GetContainerCount(containerId) or 0;
        containers[#containers + 1] = {
            id = containerId,
            name = containerNames[containerId] or ('Container ' .. tostring(containerId)),
            used = usedCount,
            capacity = maxCount,
        };
        if maxCount > 0 then
            for slot = 1, maxCount do
                local item = inv:GetContainerItem(containerId, slot);
                if item ~= nil and item.Id ~= nil and item.Id > 0 and item.Id ~= 65535 and (item.Count or 0) > 0 then
                    local r = resMgr and resMgr:GetItemById(item.Id) or nil;
                    local key = tostring(containerId) .. ':' .. tostring(slot);
                    local name = nil;
                    local logName = nil;
                    local stackSize = nil;
                    local itemType = nil;
                    if r ~= nil then
                        if r.Name ~= nil then name = r.Name[1] or r.Name[0] or r.Name[2]; end
                        if r.LogNameSingular ~= nil then logName = r.LogNameSingular[1] or r.LogNameSingular[0] or r.LogNameSingular[2]; end
                        stackSize = r.StackSize;
                        itemType = r.Type;
                    end
                    items[#items + 1] = {
                        item_id = item.Id,
                        name = name,
                        log_name = logName,
                        count = item.Count,
                        flags = item.Flags,
                        index = item.Index,
                        container_id = containerId,
                        container_name = containerNames[containerId] or ('Container ' .. tostring(containerId)),
                        slot = slot,
                        stack_size = stackSize,
                        item_type = itemType,
                        equipped_slots = equippedLookup[key] or {},
                        extra_hex = item_extra_hex(item),
                    };
                end
            end
        end
    end
    return items, containers, equipped;
end

local function parse_currency_packet(data, fields)
    if data == nil then return {}; end
    local out = {};
    for _, field in ipairs(fields) do
        local ok, value = pcall(struct.unpack, field.fmt, data, field.offset);
        if ok then out[field.key] = value; end
    end
    return out;
end

local function parse_currency_packet_113(data)
    return parse_currency_packet(data, currencyFields113);
end

local function parse_currency_packet_118(data)
    return parse_currency_packet(data, currencyFields118);
end

local function parse_conquest_packet_05e(data)
    if data == nil then return {}; end
    local out = {};
    local ok, imperial = pcall(struct.unpack, 'H', data, 0xB1);
    if ok then out.imperial_standing = imperial; end
    return out;
end

local function build_key_items()
    local player = AshitaCore:GetMemoryManager():GetPlayer();
    if player == nil then return {}; end
    local rows = {};
    -- Ashita exposes ownership through IPlayer:HasKeyItem(id).  Keep the
    -- export conservative: ownership is authoritative, names stay nil unless
    -- a verified resource name is available elsewhere.  Never guess KI names.
    for id = 0, 4095 do
        local owned = safe_call(player, 'HasKeyItem', id);
        if owned == true then
            rows[#rows + 1] = { key_item_id = id, owned = true };
        end
    end
    return rows;
end

local function build_progression()
    local player = AshitaCore:GetMemoryManager():GetPlayer();
    if player == nil then return {}; end
    local mainJob = safe_call(player, 'GetMainJob');
    return {
        main_job = mainJob,
        sub_job = safe_call(player, 'GetSubJob'),
        main_job_level = safe_call(player, 'GetMainJobLevel'),
        sub_job_level = safe_call(player, 'GetSubJobLevel'),
        nation = safe_call(player, 'GetNation'),
        exp_current = safe_call(player, 'GetExpCurrent'),
        exp_needed = safe_call(player, 'GetExpNeeded'),
        limit_points = safe_call(player, 'GetLimitPoints'),
        merit_points = safe_call(player, 'GetMeritPoints'),
        merit_points_max = safe_call(player, 'GetMeritPointsMax'),
        capacity_points = mainJob and safe_call(player, 'GetCapacityPoints', mainJob) or nil,
        job_points = mainJob and safe_call(player, 'GetJobPoints', mainJob) or nil,
        job_points_spent = mainJob and safe_call(player, 'GetJobPointsSpent', mainJob) or nil,
        mastery_exp = safe_call(player, 'GetMasteryExp'),
        mastery_exp_needed = safe_call(player, 'GetMasteryExpNeeded'),
        unity_accolades = safe_call(player, 'GetUnityPoints'),
    };
end

local function build_currency_state()
    local inv = AshitaCore:GetMemoryManager():GetInventory();
    local gil = nil;
    if inv ~= nil then
        local g = inv:GetContainerItem(0, 0);
        if g ~= nil then gil = g.Count; end
    end

    -- Currencies I + II are the semantic baseline. Points is overlaid by the local API
    -- for values it already tracks continuously (notably Sparks/Accolades/progression).
    local c = parse_currency_packet_113(lastCurrencyPacket113);
    local c2 = parse_currency_packet_118(lastCurrencyPacket118);
    for k, v in pairs(c2) do c[k] = v; end

    -- Older conquest/besieged response kept only as a fallback for Imperial Standing.
    if c.imperial_standing == nil then
        local cq = parse_conquest_packet_05e(lastConquestPacket05E);
        for k, v in pairs(cq) do c[k] = v; end
    end

    c.gil = gil;
    local player = AshitaCore:GetMemoryManager():GetPlayer();
    if c.accolades == nil and player ~= nil then c.accolades = safe_call(player, 'GetUnityPoints'); end
    return c;
end

local function output_path()
    return ('%s\\config\\addons\\%s\\snapshot-v06.json'):fmt(AshitaCore:GetInstallPath(), addon.name);
end

local function ensure_output_dir()
    local path = ('%s\\config\\addons\\%s\\'):fmt(AshitaCore:GetInstallPath(), addon.name);
    if not ashita.fs.exists(path) then ashita.fs.create_dir(path); end
end

local function write_snapshot(reason)
    local mm = AshitaCore:GetMemoryManager();
    if mm == nil or mm:GetInventory() == nil then return false; end
    ensure_output_dir();
    local items, containers, equipped = build_inventory();
    local party = mm:GetParty();
    exportSequence = exportSequence + 1;
    local exportedAt = os.time();
    local containerIds = {};
    for _, c in ipairs(containers) do containerIds[#containerIds + 1] = c.id; end
    local snapshot = {
        schema = BRIDGE_SCHEMA,
        bridge_version = addon.version,
        exported_at_unix = exportedAt,
        exported_reason = reason or 'manual',
        capture = {
            sequence = exportSequence,
            exported_at_unix = exportedAt,
            reason = reason or 'manual',
            currency_0x113_seen = lastCurrencyPacket113 ~= nil,
            currency_0x118_seen = lastCurrencyPacket118 ~= nil,
            conquest_0x05e_seen = lastConquestPacket05E ~= nil,
            container_ids = containerIds,
            physical_stack_count = #items,
            equipped_slot_count = #equipped,
        },
        character = {
            name = player_name(),
            zone_id = party and party:GetMemberZone(0) or nil,
        },
        containers = containers,
        items = items,
        equipped = equipped,
        key_items = build_key_items(),
        currencies = build_currency_state(),
        progression = build_progression(),
        raw = {
            currency_0x113_hex = bytes_to_hex(lastCurrencyPacket113, 512),
            currency_0x118_hex = bytes_to_hex(lastCurrencyPacket118, 512),
            conquest_0x05E_hex = bytes_to_hex(lastConquestPacket05E, 512),
        },
        capabilities = {
            full_physical_inventory = true,
            equipped_mapping = true,
            item_extra_hex = true,
            gil = true,
            semantic_currency_0x113 = true,
            semantic_currency_0x118 = true,
            common_currencies = true,
            key_items = true,
            progression = true,
            raw_currency_packet_capture = true,
            raw_currency2_packet_capture = true,
            points_overlay_supported = true,
            source_freshness_metadata = true,
            legacy_v05_api_compat = true,
        },
    };

    local tmp = output_path() .. '.tmp';
    local f = io.open(tmp, 'w+');
    if f == nil then
        print(chat.header(addon.name):append(chat.error('Could not open snapshot file for writing.')));
        return false;
    end
    f:write(json_encode(snapshot));
    f:write('\n');
    f:close();
    os.remove(output_path());
    local ok = os.rename(tmp, output_path());
    if not ok then
        print(chat.header(addon.name):append(chat.error('Could not replace snapshot file.')));
        return false;
    end
    lastExportClock = os.time();
    return true;
end

local function request_currency_state()
    pcall(function()
        AshitaCore:GetPacketManager():AddOutgoingPacket(0x010F, struct.pack('L', 0):totable());
    end);
    pcall(function()
        AshitaCore:GetPacketManager():AddOutgoingPacket(0x0115, struct.pack('L', 0):totable());
    end);
    pcall(function()
        AshitaCore:GetPacketManager():AddOutgoingPacket(0x005A, struct.pack('L', 0):totable());
    end);
end

local function queue_export(delay, reason)
    if exportQueued then return; end
    exportQueued = true;
    ashita.tasks.once(delay or 1, function()
        exportQueued = false;
        write_snapshot(reason or 'event');
    end);
end

ashita.events.register('load', 'gearplaner_bridge_load_cb', function()
    ensure_output_dir();
    ashita.tasks.once(2, function()
        request_currency_state();
        queue_export(2, 'load');
    end);
    print(chat.header(addon.name):append(chat.message('0.6 bridge loaded. Use /gp export for a fresh unified snapshot.')));
end);

ashita.events.register('login', 'gearplaner_bridge_login_cb', function()
    lastCurrencyPacket113 = nil;
    lastCurrencyPacket118 = nil;
    lastConquestPacket05E = nil;
    ashita.tasks.once(3, function()
        request_currency_state();
        queue_export(2, 'login');
    end);
end);

ashita.events.register('packet_in', 'gearplaner_bridge_packet_cb', function(e)
    if e.id == 0x0113 then
        lastCurrencyPacket113 = e.data_modified or e.data;
        queue_export(0.5, 'currency-0x113');
        return;
    end
    if e.id == 0x0118 then
        lastCurrencyPacket118 = e.data_modified or e.data;
        queue_export(0.5, 'currency-0x118');
        return;
    end
    if e.id == 0x005E then
        lastConquestPacket05E = e.data_modified or e.data;
        queue_export(0.5, 'conquest-0x05e');
        return;
    end
    if e.id == 0x000A then
        ashita.tasks.once(3, function()
            request_currency_state();
            queue_export(2, 'zone');
        end);
        return;
    end
    if T{ 0x001D, 0x001F, 0x0037, 0x0050, 0x0051, 0x0116, 0x0117 }:hasval(e.id) then
        queue_export(1, 'inventory');
    end
end);

ashita.events.register('packet_out', 'gearplaner_bridge_packet_out_cb', function(e)
    if T{ 0x001A, 0x0050, 0x0051, 0x0052, 0x0053, 0x0061, 0x0102, 0x0111 }:hasval(e.id) then
        queue_export(1, 'inventory-out');
    end
end);

ashita.events.register('d3d_present', 'gearplaner_bridge_tick_cb', function()
    if os.time() - lastExportClock >= EXPORT_INTERVAL then
        queue_export(0, 'periodic');
    end
end);

ashita.events.register('command', 'gearplaner_bridge_command_cb', function(e)
    local args = e.command:args();
    if #args == 0 or not args[1]:any('/gp', '/gearplaner', '/gearplanerbridge') then return; end
    e.blocked = true;
    if #args == 1 or args[2]:any('export', 'snapshot', 'refresh') then
        request_currency_state();
        ashita.tasks.once(0.5, function()
            if write_snapshot('manual') then
                print(chat.header(addon.name):append(chat.message('Snapshot exported: ')):append(chat.success(output_path())));
            end
        end);
        return;
    end
    if args[2]:any('path') then
        print(chat.header(addon.name):append(chat.message(output_path())));
        return;
    end
    if args[2]:any('help') then
        print(chat.header(addon.name):append(chat.message('/gp export | /gp path | /gp help')));
        return;
    end
end);
