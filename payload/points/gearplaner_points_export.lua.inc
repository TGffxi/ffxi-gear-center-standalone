-- GEARPLANER_POINTS_EXPORT_BEGIN_v05
-- Gearplaner 0.5 read-only export hook for Shinzaku Points.
-- This block deliberately reuses Points' existing tValues instead of reparsing
-- the same packets in another addon. It is appended to points.lua so the local
-- tValues/points variables remain in lexical scope.
local GP_POINTS_SCHEMA = 'gearplaner.points-state/0.5';
local GP_POINTS_EXPORT_INTERVAL = 5;
local gp_points_last_export = 0;

local function gp_json_escape(s)
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

local function gp_is_array(t)
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

local function gp_json_encode(v)
    local tv = type(v);
    if v == nil then return 'null'; end
    if tv == 'boolean' then return v and 'true' or 'false'; end
    if tv == 'number' then
        if v ~= v or v == math.huge or v == -math.huge then return 'null'; end
        return tostring(v);
    end
    if tv == 'string' then return gp_json_escape(v); end
    if tv ~= 'table' then return gp_json_escape(tostring(v)); end

    local arr, n = gp_is_array(v);
    local out = {};
    if arr then
        for i = 1, n do out[#out + 1] = gp_json_encode(v[i]); end
        return '[' .. table.concat(out, ',') .. ']';
    end
    for k, value in pairs(v) do
        out[#out + 1] = gp_json_escape(k) .. ':' .. gp_json_encode(value);
    end
    table.sort(out);
    return '{' .. table.concat(out, ',') .. '}';
end

local function gp_copy_array(src)
    local out = {};
    if type(src) ~= 'table' then return out; end
    for i, v in ipairs(src) do out[i] = v; end
    return out;
end

local function gp_points_output_path()
    return ('%s\\config\\addons\\points\\gearplaner-points-v05.json'):fmt(AshitaCore:GetInstallPath());
end

local function gp_points_ensure_dir()
    local path = ('%s\\config\\addons\\points\\'):fmt(AshitaCore:GetInstallPath());
    if not ashita.fs.exists(path) then ashita.fs.create_dir(path); end
end

local function gp_points_build_state()
    local d = tValues.default or {};
    local exp = d.exp or {};
    local lim = d.limit or {};
    local cap = d.capacity or {};
    local mastery = d.mastery or {};
    local entity = GetPlayerEntity();
    local mm = AshitaCore:GetMemoryManager();
    local p = mm and mm:GetPlayer() or nil;
    local party = mm and mm:GetParty() or nil;

    return {
        schema = GP_POINTS_SCHEMA,
        source = {
            addon = addon.name or 'points',
            version = addon.version,
            hook = 'gearplaner-v05',
        },
        exported_at_unix = os.time(),
        ready = points.loaded == true,
        character = {
            name = entity and entity.Name or nil,
            zone_id = party and party:GetMemberZone(0) or nil,
            main_job = p and p:GetMainJob() or nil,
            main_job_level = p and p:GetMainJobLevel() or nil,
        },
        default = {
            sparks = d.sparks,
            accolades = d.accolades,
            exp = { curr = exp.curr, max = exp.max },
            limit = { curr = lim.curr, points = lim.points, maxpoints = lim.maxpoints },
            capacity = { curr = cap.curr, points = cap.points },
            mastery = { curr = mastery.curr, max = mastery.max },
            mBreaker = d.mBreaker,
            xpChain = d.xpChain,
            xpTimer = d.xpTimer,
            estXpHour = d.estXpHour,
            estMpHour = d.estMpHour,
            cpChain = d.cpChain,
            cpTimer = d.cpTimer,
            estCpHour = d.estCpHour,
            estJpHour = d.estJpHour,
            epChain = d.epChain,
            epTimer = d.epTimer,
            estEpHour = d.estEpHour,
        },
        events = {
            event_timer = tValues.eventTimer,
            zone_timer_started = tValues.zoneTimer,
            dynamis = {
                key_items = gp_copy_array((tValues.dynamis or {}).keyItems),
            },
            abyssea = {
                pearlescent = (tValues.abyssea or {}).pearlescent,
                azure = (tValues.abyssea or {}).azure,
                ruby = (tValues.abyssea or {}).ruby,
                amber = (tValues.abyssea or {}).amber,
                golden = (tValues.abyssea or {}).golden,
                silvery = (tValues.abyssea or {}).silvery,
                ebon = (tValues.abyssea or {}).ebon,
            },
            assault = {
                objective = (tValues.assault or {}).objective,
                timer = (tValues.assault or {}).timer,
            },
            nyzul = {
                floor = (tValues.nyzul or {}).floor,
                objective = (tValues.nyzul or {}).objective,
            },
            voidwatch = {
                red = (tValues.voidwatch or {}).red,
                blue = (tValues.voidwatch or {}).blue,
                green = (tValues.voidwatch or {}).green,
                yellow = (tValues.voidwatch or {}).yellow,
                white = (tValues.voidwatch or {}).white,
            },
            omen = {
                main_objective = (tValues.omen or {}).mainObjective,
                add_objectives = gp_copy_array((tValues.omen or {}).addObjectives),
            },
            odyssey = {
                segments = (tValues.odyssey or {}).segments,
                total_segments = (tValues.odyssey or {}).totalSegments,
                izzat = (tValues.odyssey or {}).izzat,
                moglophone_mastery = (tValues.odyssey or {}).mMastery,
                izzat_costs = gp_copy_array((tValues.odyssey or {}).izCosts),
            },
            sortie = {
                gallimaufry = (tValues.sortie or {}).gallimaufry,
            },
        },
    };
end

local function gp_points_export(reason)
    if not points.loaded then return false; end
    gp_points_ensure_dir();
    local state = gp_points_build_state();
    state.exported_reason = reason or 'periodic';
    local path = gp_points_output_path();
    local tmp = path .. '.tmp';
    local f = io.open(tmp, 'w+');
    if f == nil then return false; end
    f:write(gp_json_encode(state));
    f:write('\n');
    f:close();
    os.remove(path);
    if not os.rename(tmp, path) then
        os.remove(tmp);
        return false;
    end
    gp_points_last_export = os.time();
    return true;
end

ashita.events.register('d3d_present', 'gearplaner_points_export_tick_v05', function()
    if points.loaded and (os.time() - gp_points_last_export >= GP_POINTS_EXPORT_INTERVAL) then
        gp_points_export('periodic');
    end
end);

ashita.events.register('command', 'gearplaner_points_export_command_v05', function(e)
    local args = e.command:args();
    if #args == 0 or args[1] ~= '/gppoints' then return; end
    e.blocked = true;
    if #args == 1 or args[2] == 'export' or args[2] == 'refresh' then
        if gp_points_export('manual') then
            print('[Gearplaner] Points state exported: ' .. gp_points_output_path());
        else
            print('[Gearplaner] Points is not ready yet; no state exported.');
        end
    elseif args[2] == 'path' then
        print('[Gearplaner] ' .. gp_points_output_path());
    else
        print('[Gearplaner] /gppoints export | /gppoints path');
    end
end);
-- GEARPLANER_POINTS_EXPORT_END_v05
