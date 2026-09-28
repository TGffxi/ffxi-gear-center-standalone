-- FFXI Gear Center - unified LuAshitacast live snapshot bridge
-- Read-only exporter for equipment, ordinary inventory items, key-item ownership,
-- player-exposed point balances, packet-backed content currencies and
-- LAC-decoded augment details. Currency packets are observed passively; this
-- bridge never injects requests into the game client.

local bridge = {};

local inventory = AshitaCore:GetMemoryManager():GetInventory();
local player = AshitaCore:GetMemoryManager():GetPlayer();
local resources = AshitaCore:GetResourceManager();

local BAG_NAMES = {
    [0] = 'Inventory', [1] = 'Safe', [2] = 'Storage', [3] = 'Temporary',
    [4] = 'Locker', [5] = 'Satchel', [6] = 'Sack', [7] = 'Case',
    [8] = 'Wardrobe 1', [9] = 'Safe 2', [10] = 'Wardrobe 2',
    [11] = 'Wardrobe 3', [12] = 'Wardrobe 4', [13] = 'Wardrobe 5',
    [14] = 'Wardrobe 6', [15] = 'Wardrobe 7', [16] = 'Wardrobe 8',
    [17] = 'Recycle'
};

local lastCheck = 0;
local lastCounter = nil;
local pendingSince = nil;
local lastExport = 0;
local CHECK_INTERVAL = 2;
local STABLE_SECONDS = 3;
local MAX_REFRESH_SECONDS = 300;

local currencyPackets = {
    [0x113] = { capturedAt = nil, values = {} },
    [0x118] = { capturedAt = nil, values = {} }
};

-- Packet offsets and primitive types follow the current retail Currencies I
-- (0x113) and Currencies II (0x118) layouts. Lua struct offsets are one-based,
-- therefore readCurrencyPacket adds one before calling struct.unpack.
local CURRENCY_FIELDS = {
    [0x113] = {
        {0x04,'i',"Conquest Points (San d'Oria)"}, {0x08,'i','Conquest Points (Bastok)'},
        {0x0C,'i','Conquest Points (Windurst)'}, {0x10,'H','Beastman Seals'},
        {0x12,'H','Kindred Seals'}, {0x14,'H','Kindred Crests'},
        {0x16,'H','High Kindred Crests'}, {0x18,'H','Sacred Kindred Crests'},
        {0x1A,'H','Ancient Beastcoins'}, {0x1C,'H','Valor Points'}, {0x1E,'H','Scylds'},
        {0x20,'i','Guild Points (Fishing)'}, {0x24,'i','Guild Points (Woodworking)'},
        {0x28,'i','Guild Points (Smithing)'}, {0x2C,'i','Guild Points (Goldsmithing)'},
        {0x30,'i','Guild Points (Weaving)'}, {0x34,'i','Guild Points (Leathercraft)'},
        {0x38,'i','Guild Points (Bonecraft)'}, {0x3C,'i','Guild Points (Alchemy)'},
        {0x40,'i','Guild Points (Cooking)'}, {0x44,'i','Cinders'},
        {0x48,'B','Synergy Fewell (Fire)'}, {0x49,'B','Synergy Fewell (Ice)'},
        {0x4A,'B','Synergy Fewell (Wind)'}, {0x4B,'B','Synergy Fewell (Earth)'},
        {0x4C,'B','Synergy Fewell (Lightning)'}, {0x4D,'B','Synergy Fewell (Water)'},
        {0x4E,'B','Synergy Fewell (Light)'}, {0x4F,'B','Synergy Fewell (Dark)'},
        {0x50,'i','Ballista Points'}, {0x54,'i','Fellow Points'},
        {0x58,'H',"Chocobucks (San d'Oria)"}, {0x5A,'H','Chocobucks (Bastok)'},
        {0x5C,'H','Chocobucks (Windurst)'}, {0x5E,'H','Daily Tally'},
        {0x60,'i','Research Marks'}, {0x64,'B','Wizened Tunnel Worms'},
        {0x65,'B','Wizened Morion Worms'}, {0x66,'B','Wizened Phantom Worms'},
        {0x68,'i','Moblin Marbles'}, {0x6C,'H','Infamy'}, {0x6E,'H','Prestige'},
        {0x70,'i','Legion Points'}, {0x74,'i','Sparks of Eminence'},
        {0x78,'i','Shining Stars'}, {0x7C,'i','Imperial Standing'},
        {0x80,'i','Assault Points (Leujaoam Sanctum)'}, {0x84,'i','Assault Points (M.J.T.G.)'},
        {0x88,'i','Assault Points (Lebros Cavern)'}, {0x8C,'i','Assault Points (Periqia)'},
        {0x90,'i','Assault Points (Ilrusi Atoll)'}, {0x94,'i','Nyzul Tokens'},
        {0x98,'i','Zeni'}, {0x9C,'i','Jettons'}, {0xA0,'i','Therion Ichor'},
        {0xA4,'i','Allied Notes'}, {0xA8,'H','A.M.A.N. Vouchers Stored'},
        {0xAA,'H','Login Points'}, {0xAC,'i','Cruor'},
        {0xB0,'i','Resistance Credits'}, {0xB4,'i','Dominion Notes'},
        {0xB8,'B','5th Echelon Battle Trophies'}, {0xB9,'B','4th Echelon Battle Trophies'},
        {0xBA,'B','3rd Echelon Battle Trophies'}, {0xBB,'B','2nd Echelon Battle Trophies'},
        {0xBC,'B','1st Echelon Battle Trophies'}, {0xBD,'B','Cave Conservation Points'},
        {0xBE,'B','Imperial Army ID Tags'}, {0xBF,'B','Op Credits'},
        {0xC0,'i','Traverser Stones'}, {0xC4,'i','Voidstones'},
        {0xC8,'i',"Kupofried's Corundums"}, {0xCC,'B','Moblin Pheromone Sacks'},
        {0xCE,'B',"Rem's Tale Chapter 1"}, {0xCF,'B',"Rem's Tale Chapter 2"},
        {0xD0,'B',"Rem's Tale Chapter 3"}, {0xD1,'B',"Rem's Tale Chapter 4"},
        {0xD2,'B',"Rem's Tale Chapter 5"}, {0xD3,'B',"Rem's Tale Chapter 6"},
        {0xD4,'B',"Rem's Tale Chapter 7"}, {0xD5,'B',"Rem's Tale Chapter 8"},
        {0xD6,'B',"Rem's Tale Chapter 9"}, {0xD7,'B',"Rem's Tale Chapter 10"},
        {0xE0,'i','Reclamation Marks'}, {0xE4,'i','Unity Accolades'},
        {0xE8,'H','Fire Crystals'}, {0xEA,'H','Ice Crystals'},
        {0xEC,'H','Wind Crystals'}, {0xEE,'H','Earth Crystals'},
        {0xF0,'H','Lightning Crystals'}, {0xF2,'H','Water Crystals'},
        {0xF4,'H','Light Crystals'}, {0xF6,'H','Dark Crystals'}, {0xF8,'i','Deeds'}
    },
    [0x118] = {
        {0x04,'i','Bayld'}, {0x08,'H','Kinetic Units'}, {0x0A,'B','Coalition Imprimaturs'},
        {0x0B,'B','Mystical Canteens'}, {0x0C,'i','Obsidian Fragments'},
        {0x10,'H','Lebondopt Wings Stored'}, {0x12,'H','Pulchridopt Wings Stored'},
        {0x14,'i','Mweya Plasm Corpuscles'}, {0x18,'B','Ghastly Stones Stored'},
        {0x19,'B','Ghastly Stones +1 Stored'}, {0x1A,'B','Ghastly Stones +2 Stored'},
        {0x1B,'B','Verdigris Stones Stored'}, {0x1C,'B','Verdigris Stones +1 Stored'},
        {0x1D,'B','Verdigris Stones +2 Stored'}, {0x1E,'B','Wailing Stones Stored'},
        {0x1F,'B','Wailing Stones +1 Stored'}, {0x20,'B','Wailing Stones +2 Stored'},
        {0x21,'B','Snowslit Stones Stored'}, {0x22,'B','Snowslit Stones +1 Stored'},
        {0x23,'B','Snowslit Stones +2 Stored'}, {0x24,'B','Snowtip Stones Stored'},
        {0x25,'B','Snowtip Stones +1 Stored'}, {0x26,'B','Snowtip Stones +2 Stored'},
        {0x27,'B','Snowdim Stones Stored'}, {0x28,'B','Snowdim Stones +1 Stored'},
        {0x29,'B','Snowdim Stones +2 Stored'}, {0x2A,'B','Snoworb Stones Stored'},
        {0x2B,'B','Snoworb Stones +1 Stored'}, {0x2C,'B','Snoworb Stones +2 Stored'},
        {0x2D,'B','Leafslit Stones Stored'}, {0x2E,'B','Leafslit Stones +1 Stored'},
        {0x2F,'B','Leafslit Stones +2 Stored'}, {0x30,'B','Leaftip Stones Stored'},
        {0x31,'B','Leaftip Stones +1 Stored'}, {0x32,'B','Leaftip Stones +2 Stored'},
        {0x33,'B','Leafdim Stones Stored'}, {0x34,'B','Leafdim Stones +1 Stored'},
        {0x35,'B','Leafdim Stones +2 Stored'}, {0x36,'B','Leaforb Stones Stored'},
        {0x37,'B','Leaforb Stones +1 Stored'}, {0x38,'B','Leaforb Stones +2 Stored'},
        {0x39,'B','Duskslit Stones Stored'}, {0x3A,'B','Duskslit Stones +1 Stored'},
        {0x3B,'B','Duskslit Stones +2 Stored'}, {0x3C,'B','Dusktip Stones Stored'},
        {0x3D,'B','Dusktip Stones +1 Stored'}, {0x3E,'B','Dusktip Stones +2 Stored'},
        {0x3F,'B','Duskdim Stones Stored'}, {0x40,'B','Duskdim Stones +1 Stored'},
        {0x41,'B','Duskdim Stones +2 Stored'}, {0x42,'B','Duskorb Stones Stored'},
        {0x43,'B','Duskorb Stones +1 Stored'}, {0x44,'B','Duskorb Stones +2 Stored'},
        {0x45,'B','Pellucid Stones Stored'}, {0x46,'B','Fern Stones Stored'},
        {0x47,'B','Taupe Stones Stored'}, {0x48,'H','Mellidopt Wings Stored'},
        {0x4A,'H','Escha Beads'}, {0x4C,'i','Escha Silt'}, {0x50,'i','Potpourri'},
        {0x54,'i','Hallmarks'}, {0x58,'i','Total Hallmarks'},
        {0x5C,'i','Badges of Gallantry'}, {0x60,'i','Crafter Points'},
        {0x64,'B','Fire Crystals Set'}, {0x65,'B','Ice Crystals Set'},
        {0x66,'B','Wind Crystals Set'}, {0x67,'B','Earth Crystals Set'},
        {0x68,'B','Lightning Crystals Set'}, {0x69,'B','Water Crystals Set'},
        {0x6A,'B','Light Crystals Set'}, {0x6B,'B','Dark Crystals Set'},
        {0x6C,'B','MC-S-SR01s Set'}, {0x6D,'B','MC-S-SR02s Set'},
        {0x6E,'B','MC-S-SR03s Set'}, {0x6F,'B','Liquefaction Spheres Set'},
        {0x70,'B','Induration Spheres Set'}, {0x71,'B','Detonation Spheres Set'},
        {0x72,'B','Scission Spheres Set'}, {0x73,'B','Impaction Spheres Set'},
        {0x74,'B','Reverberation Spheres Set'}, {0x75,'B','Transfixion Spheres Set'},
        {0x76,'B','Compression Spheres Set'}, {0x77,'B','Fusion Spheres Set'},
        {0x78,'B','Distortion Spheres Set'}, {0x79,'B','Fragmentation Spheres Set'},
        {0x7A,'B','Gravitation Spheres Set'}, {0x7B,'B','Light Spheres Set'},
        {0x7C,'B','Darkness Spheres Set'}, {0x80,'i','Silver A.M.A.N. Vouchers Stored'},
        {0x84,'i','Domain Points'}, {0x88,'i','Domain Points Earned Today'},
        {0x8C,'i','Mog Segments'}, {0x90,'i','Gallimaufry'},
        {0x94,'i','Imperial Standing Accolades'}, {0x98,'i','Temenos Units'},
        {0x9C,'i','Apollyon Units'}
    }
};

local function jsonEscape(value)
    local s = tostring(value or '');
    s = s:gsub('\\', '\\\\');
    s = s:gsub('"', '\\"');
    s = s:gsub('\b', '\\b');
    s = s:gsub('\f', '\\f');
    s = s:gsub('\n', '\\n');
    s = s:gsub('\r', '\\r');
    s = s:gsub('\t', '\\t');
    return '"' .. s .. '"';
end

local function boolJson(value)
    if value == true then return 'true'; end
    if value == false then return 'false'; end
    return 'null';
end

local function nullableString(value)
    if value == nil then return 'null'; end
    return jsonEscape(value);
end

local function nullableNumber(value)
    if type(value) ~= 'number' then return 'null'; end
    return tostring(value);
end

local function getProfileDir()
    if gProfile == nil or type(gProfile.FilePath) ~= 'string' then
        return nil;
    end
    return gProfile.FilePath:match('^(.*[\\/])');
end

local function extraHex(item)
    local ok, data = pcall(function() return item.Extra:totable(); end);
    if not ok or type(data) ~= 'table' then return ''; end
    local keys = {};
    for k,_ in pairs(data) do
        if type(k) == 'number' then table.insert(keys, k); end
    end
    table.sort(keys);
    local out = {};
    for _,k in ipairs(keys) do
        local v = tonumber(data[k]) or 0;
        table.insert(out, string.format('%02X', v));
    end
    return table.concat(out);
end

local function augmentStrings(augment)
    local out = {};
    if type(augment) ~= 'table' or type(augment.Augs) ~= 'table' then return out; end
    for _,v in pairs(augment.Augs) do
        if type(v) == 'table' and type(v.String) == 'string' then
            table.insert(out, v.String);
        end
    end
    table.sort(out);
    return out;
end

local function encodeStringArray(values)
    local out = {};
    for _,v in ipairs(values) do table.insert(out, jsonEscape(v)); end
    return '[' .. table.concat(out, ',') .. ']';
end

local function safeField(object, key)
    if object == nil then return nil; end
    local ok, value = pcall(function() return object[key]; end);
    if not ok then return nil; end
    return value;
end

local function localizedName(resource)
    local names = safeField(resource, 'Name');
    if names == nil then return nil; end
    local ok, value = pcall(function() return names[1]; end);
    if ok and type(value) == 'string' and value ~= '' then return value; end
    return nil;
end

local function itemResource(itemId)
    if resources == nil then return nil; end
    local ok, value = pcall(function() return resources:GetItemById(itemId); end);
    if not ok then return nil; end
    return value;
end

local function encodeItem(container, slot, item)
    local resource = itemResource(item.Id);
    local slots = tonumber(safeField(resource, 'Slots')) or 0;
    local augment = nil;
    local augmentError = nil;
    local ok, result = pcall(function() return gData.GetAugment(item); end);
    if ok and type(result) == 'table' then
        augment = result;
    elseif not ok then
        augmentError = tostring(result);
    end

    local strings = augmentStrings(augment);
    local trialComplete = nil;
    if augment ~= nil then trialComplete = augment.TrialComplete; end
    local parts = {
        '"item_id":' .. tostring(item.Id),
        '"bag_id":' .. tostring(container),
        '"bag_name":' .. jsonEscape(BAG_NAMES[container] or ('Container ' .. tostring(container))),
        '"bag_slot":' .. tostring(slot),
        '"count":' .. tostring(item.Count or 0),
        '"item_name":' .. nullableString(localizedName(resource)),
        '"is_equipment":' .. boolJson(slots > 0),
        '"resource_flags":' .. nullableNumber(tonumber(safeField(resource, 'Flags'))),
        '"resource_type":' .. nullableNumber(tonumber(safeField(resource, 'Type'))),
        '"stack_size":' .. nullableNumber(tonumber(safeField(resource, 'StackSize'))),
        '"slots_mask":' .. nullableNumber(tonumber(safeField(resource, 'Slots'))),
        '"jobs_mask":' .. nullableNumber(tonumber(safeField(resource, 'Jobs'))),
        '"level":' .. nullableNumber(tonumber(safeField(resource, 'Level'))),
        '"item_level":' .. nullableNumber(tonumber(safeField(resource, 'ItemLevel'))),
        '"superior_level":' .. nullableNumber(tonumber(safeField(resource, 'SuperiorLevel'))),
        '"raw_extra_hex":' .. jsonEscape(extraHex(item)),
        '"augment_type":' .. nullableString(augment and augment.Type or nil),
        '"path":' .. nullableString(augment and augment.Path or nil),
        '"rank":' .. nullableNumber(augment and augment.Rank or nil),
        '"trial":' .. nullableNumber(augment and augment.Trial or nil),
        '"trial_complete":' .. boolJson(trialComplete),
        '"augment_strings":' .. encodeStringArray(strings),
        '"augment_error":' .. nullableString(augmentError)
    };
    return '{' .. table.concat(parts, ',') .. '}';
end

local function collectKeyItems()
    local rows = {};
    if player == nil then return rows, 'ERROR'; end
    for id = 0,4095 do
        local ok, owned = pcall(function() return player:HasKeyItem(id); end);
        if ok and owned == true then
            table.insert(rows, '{' .. table.concat({
                '"key_item_id":' .. tostring(id),
                '"name":null',
                '"owned":true'
            }, ',') .. '}');
        end
    end
    return rows, 'COMPLETE';
end

local function addPlayerPoint(rows, key, name, methodName)
    if player == nil then return; end
    local ok, value = pcall(function() return player[methodName](player); end);
    if ok and type(value) == 'number' then
        table.insert(rows, '{' .. table.concat({
            '"currency_key":' .. jsonEscape(key),
            '"name":' .. jsonEscape(name),
            '"quantity":' .. tostring(value),
            '"scope":' .. jsonEscape('player_points')
        }, ',') .. '}');
    end
end

local function currencyKey(name)
    local key = string.lower(tostring(name or ''));
    key = key:gsub("'", '');
    key = key:gsub('[^%w]+', '_');
    key = key:gsub('^_+', ''):gsub('_+$', '');
    return key;
end

local function readCurrencyPacket(packetId, data)
    local fields = CURRENCY_FIELDS[packetId];
    if type(fields) ~= 'table' or type(data) ~= 'string' then return false; end
    local values = {};
    local parsed = 0;
    for _,field in ipairs(fields) do
        local ok, value = pcall(function()
            return struct.unpack(field[2], data, field[1] + 1);
        end);
        if ok and type(value) == 'number' and value >= 0 then
            values[field[3]] = value;
            parsed = parsed + 1;
        end
    end
    if parsed == 0 then return false; end
    currencyPackets[packetId].values = values;
    currencyPackets[packetId].capturedAt = os.date('!%Y-%m-%dT%H:%M:%SZ');
    return true;
end

local function addPacketCurrencies(rows, packetId)
    local state = currencyPackets[packetId];
    if state == nil or type(state.values) ~= 'table' then return; end
    for _,field in ipairs(CURRENCY_FIELDS[packetId] or {}) do
        local name = field[3];
        local value = state.values[name];
        if type(value) == 'number' and value >= 0 then
            table.insert(rows, '{' .. table.concat({
                '"currency_key":' .. jsonEscape(currencyKey(name)),
                '"name":' .. jsonEscape(name),
                '"quantity":' .. tostring(value),
                '"scope":' .. jsonEscape('content_currency'),
                '"packet_id":' .. jsonEscape(string.format('0x%03X', packetId)),
                '"packet_captured_at":' .. nullableString(state.capturedAt)
            }, ',') .. '}');
        end
    end
end

local function collectCurrencies()
    local rows = {};
    addPlayerPoint(rows, 'limit_points', 'Limit Points', 'GetLimitPoints');
    addPlayerPoint(rows, 'merit_points', 'Merit Points', 'GetMeritPoints');
    addPlayerPoint(rows, 'assimilation_points', 'Assimilation Points', 'GetAssimilationPoints');
    addPacketCurrencies(rows, 0x113);
    addPacketCurrencies(rows, 0x118);
    return rows;
end

local function currencyCollectionStatus()
    local first = currencyPackets[0x113].capturedAt;
    local second = currencyPackets[0x118].capturedAt;
    if first ~= nil and second ~= nil then
        return 'COMPLETE', 'Currencies I and II were passively observed from the game client.';
    end
    local missing = {};
    if first == nil then table.insert(missing, '0x113'); end
    if second == nil then table.insert(missing, '0x118'); end
    return 'PARTIAL', 'Open Status > Currencies in FFXI once; waiting for packet(s) ' .. table.concat(missing, ', ') .. '.';
end

function bridge.Export()
    local profileDir = getProfileDir();
    if profileDir == nil then return false, 'profile directory unavailable'; end
    if inventory == nil then return false, 'inventory manager unavailable'; end

    local rows = {};
    local unresolvedNames = 0;
    for container = 0,17 do
        local maxCount = inventory:GetContainerCountMax(container);
        if type(maxCount) == 'number' and maxCount > 0 then
            for slot = 0,maxCount - 1 do
                local item = inventory:GetContainerItem(container, slot);
                if item ~= nil and (item.Id or 0) > 0 and (item.Count or 0) > 0 then
                    if localizedName(itemResource(item.Id)) == nil then unresolvedNames = unresolvedNames + 1; end
                    table.insert(rows, encodeItem(container, slot, item));
                end
            end
        end
    end

    local keyItems, keyItemStatus = collectKeyItems();
    local currencies = collectCurrencies();
    local currencyStatus, currencyNote = currencyCollectionStatus();

    local counter = inventory:GetContainerUpdateCounter();
    local payload = '{' .. table.concat({
        '"schema":' .. jsonEscape('ffxi-gear-center-live-snapshot-v2'),
        '"captured_at":' .. jsonEscape(os.date('!%Y-%m-%dT%H:%M:%SZ')),
        '"inventory_counter":' .. tostring(counter or 0),
        '"profile_path":' .. jsonEscape(gProfile and gProfile.FilePath or ''),
        '"collection_status":{' .. table.concat({
            '"inventory":' .. jsonEscape(unresolvedNames == 0 and 'COMPLETE' or 'PARTIAL'),
            '"unresolved_item_names":' .. tostring(unresolvedNames),
            '"key_items":' .. jsonEscape(keyItemStatus),
            '"currencies":' .. jsonEscape(currencyStatus),
            '"currency_note":' .. jsonEscape(currencyNote),
            '"currency_packet_0x113_at":' .. nullableString(currencyPackets[0x113].capturedAt),
            '"currency_packet_0x118_at":' .. nullableString(currencyPackets[0x118].capturedAt),
            '"currency_rows":' .. tostring(#currencies),
            '"currency_capture_mode":' .. jsonEscape('passive_packet_observer')
        }, ',') .. '}',
        '"items":[' .. table.concat(rows, ',') .. ']',
        '"key_items":[' .. table.concat(keyItems, ',') .. ']',
        '"currencies":[' .. table.concat(currencies, ',') .. ']'
    }, ',') .. '}';

    local target = profileDir .. 'gearcenter_augments.json';
    local temp = target .. '.tmp';
    local file, err = io.open(temp, 'wb');
    if file == nil then return false, err or 'could not open temp file'; end
    file:write(payload);
    file:flush();
    file:close();

    os.remove(target);
    local renamed, renameErr = os.rename(temp, target);
    if not renamed then
        os.remove(temp);
        return false, renameErr or 'atomic rename failed';
    end

    lastExport = os.time();
    return true, target;
end

function bridge.Tick()
    local now = os.time();
    if now - lastCheck < CHECK_INTERVAL then return; end
    lastCheck = now;
    if inventory == nil then return; end

    local counter = inventory:GetContainerUpdateCounter();
    if lastCounter == nil then
        lastCounter = counter;
        pendingSince = now;
        return;
    end

    if counter ~= lastCounter then
        lastCounter = counter;
        pendingSince = now;
        return;
    end

    if pendingSince ~= nil and (now - pendingSince) >= STABLE_SECONDS then
        bridge.Export();
        pendingSince = nil;
        return;
    end

    if lastExport == 0 or (now - lastExport) >= MAX_REFRESH_SECONDS then
        bridge.Export();
    end
end

function bridge.OnPacket(e)
    if e == nil or CURRENCY_FIELDS[e.id] == nil then return; end
    if readCurrencyPacket(e.id, e.data) then
        -- Queue the next stable export instead of doing the full inventory/KI
        -- scan inside Ashita's incoming-packet callback.
        pendingSince = os.time() - STABLE_SECONDS;
    end
end

if ashita ~= nil and ashita.events ~= nil then
    pcall(function()
        ashita.events.unregister('packet_in', 'gearcenter_currency_capture');
    end);
    ashita.events.register('packet_in', 'gearcenter_currency_capture', function(e)
        bridge.OnPacket(e);
    end);
end

return bridge;
