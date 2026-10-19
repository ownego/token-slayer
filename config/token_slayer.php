<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Hook version
    |--------------------------------------------------------------------------
    |
    | The version of the hook shipped by THIS repo. Deliberately separate from
    | the slayer-cli release tag: that tag lives in a repo this project does not
    | publish, so a hook-only change would otherwise ship with an unchanged
    | version and every update prompt would silently no-op. Bump this whenever
    | the hook template changes in a way clients must pick up.
    |
    */

    'hook_version' => (string) env('TOKEN_SLAYER_HOOK_VERSION', '7'),

    /*
    |--------------------------------------------------------------------------
    | Update pause
    |--------------------------------------------------------------------------
    |
    | Fleet-wide kill switch for client self-update. Flipping this true stops
    | every machine from updating on its next ingest round-trip, so a bad
    | release can be halted centrally instead of by reaching each developer.
    |
    */

    'updates_paused' => (bool) env('TOKEN_SLAYER_UPDATES_PAUSED', false),

    /*
    |--------------------------------------------------------------------------
    | Anthropic OAuth (server-side quota probing)
    |--------------------------------------------------------------------------
    |
    | Constants for the per-account PKCE OAuth grant the server holds to call
    | the free usage/profile APIs. The client id is Anthropic's public OAuth
    | client for Claude subscriptions — not a secret.
    |
    | `user_agent` is load-bearing: platform.claude.com's WAF returns a bare
    | 429 (no Retry-After) for the default Guzzle/curl/browser User-Agent, so
    | every request must send a claude-cli-style UA instead. `redirect_uri`
    | is the manual/paste PKCE callback used by the connect flow.
    |
    */

    'anthropic' => [
        'oauth_client_id' => env('ANTHROPIC_OAUTH_CLIENT_ID', '9d1c250a-e61b-44d9-88ed-5944d1962f5e'),
        'token_endpoint' => 'https://platform.claude.com/v1/oauth/token',
        'usage_endpoint' => 'https://api.anthropic.com/api/oauth/usage',
        'profile_endpoint' => 'https://api.anthropic.com/api/oauth/profile',
        'messages_endpoint' => 'https://api.anthropic.com/v1/messages',
        'version_header' => '2023-06-01',
        'beta_header' => 'oauth-2025-04-20',
        'user_agent' => env('ANTHROPIC_USER_AGENT', 'claude-cli/2.1.206 (external, cli)'),
        'redirect_uri' => env('ANTHROPIC_OAUTH_REDIRECT_URI', 'https://platform.claude.com/oauth/code/callback'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Session anchoring
    |--------------------------------------------------------------------------
    |
    | A minimal real inference (max_tokens 1) is sent to each account at fixed
    | clock times so Anthropic's rolling 5-hour usage window starts then. A
    | 0-token usage/beacon call does NOT start a session, so this must be a
    | genuine (tiny) message. `model` is the cheapest valid model to bill the
    | one token against.
    |
    */

    'session_anchor' => [
        'model' => env('TOKEN_SLAYER_SESSION_ANCHOR_MODEL', 'claude-haiku-4-5-20251001'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Quota probing cadence
    |--------------------------------------------------------------------------
    */

    'probe' => [
        'refresh_headroom_hours' => (int) env('TOKEN_SLAYER_PROBE_HEADROOM_HOURS', 4),
    ],

    /*
    |--------------------------------------------------------------------------
    | Model prices
    |--------------------------------------------------------------------------
    |
    | List prices used to cost each usage event, pulled from the LiteLLM price
    | file. The URL is fixed here on purpose (no env override): the feed is an
    | outside source, so where it comes from is a code change, not a setting.
    |
    */

    'model_prices' => [
        // The one file prices are read from.
        'feed_url' => 'https://raw.githubusercontent.com/BerriAI/litellm/main/model_prices_and_context_window.json',
        // Whole-request limit for one fetch; the file is about 3 MB.
        'timeout_seconds' => 20,
        // Limit for opening the connection.
        'connect_timeout_seconds' => 5,
        // A response larger than this is refused without parsing (10 MB, about 3x the real file).
        'max_bytes' => 10 * 1024 * 1024,
        // Deepest JSON nesting accepted; a real entry nests about four levels.
        'json_max_depth' => 32,
        // How long ingest reuses a model's price (or its absence) before reading the table again.
        'cache_ttl_seconds' => 3600,
        // Hourly retries only chase models first seen this recently; LiteLLM listed a new model about a day
        // after our first use of it (measured on opus-5-5). Older missing models are retried by the daily sync.
        'hourly_window_hours' => 48,
    ],

    /*
    |--------------------------------------------------------------------------
    | Usage snapshot retention
    |--------------------------------------------------------------------------
    */

    'snapshots' => [
        'retention_days' => (int) env('TOKEN_SLAYER_SNAPSHOT_RETENTION_DAYS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Account rebalance recommender
    |--------------------------------------------------------------------------
    |
    | Tunables for AccountCapacityEstimator/UserDemandEstimator/
    | AccountRebalanceRecommender. trend_window_days is the default lookback
    | when the admin has not picked a range on the Rebalance page;
    | safety_margin_percent is how much of every account's capacity is left
    | unplanned; min_history_days is the minimum days of data an account/user
    | needs before a recommendation involving it is marked confident.
    |
    | The peak-demand window is deliberately NOT tunable: a demand figure is
    | only comparable with a weekly quota if it is measured over a week.
    |
    | members_per_account is how many people an account should carry. Weekly
    | load alone does not capture the risk of a crowded account: more people
    | means more of them working at the same time, and a 5-hour window is
    | tripped by simultaneity rather than by a weekly total. It is a target
    | rather than a hard limit -- when there are more people than it allows,
    | it rises to the smallest number that fits everyone.
    |
    */

    'rebalance' => [
        'trend_window_days' => (int) env('TOKEN_SLAYER_REBALANCE_TREND_DAYS', 14),
        'members_per_account' => (int) env('TOKEN_SLAYER_REBALANCE_MEMBERS_PER_ACCOUNT', 5),
        'safety_margin_percent' => (int) env('TOKEN_SLAYER_REBALANCE_SAFETY_MARGIN', 20),
        'min_history_days' => (int) env('TOKEN_SLAYER_REBALANCE_MIN_HISTORY_DAYS', 7),
    ],

    /*
    |--------------------------------------------------------------------------
    | Battlefield sky site
    |--------------------------------------------------------------------------
    |
    | Where the battlefield's living sky (sun/moon position, day/night colour)
    | is computed for. The viewer's own clock drives the time; this only sets
    | the place. Defaults to Hanoi.
    |
    */

    'sky' => [
        'lat' => (float) env('TOKEN_SLAYER_SKY_LAT', 21.03),
        'lon' => (float) env('TOKEN_SLAYER_SKY_LON', 105.85),
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom character pose sheet limits
    |--------------------------------------------------------------------------
    |
    | What an uploaded pose sheet may look like before we spend any time on it.
    | max_pixels bounds the decoded bitmap (a tiny PNG can still expand to
    | gigabytes), so it is checked from the header before decoding.
    |
    */

    'custom_character' => [
        'max_bytes' => 4 * 1024 * 1024,
        'min_side' => 512,
        // The build runs in the request on PHP arrays that grow with the pixel count: measured with a 256M
        // memory_limit, a noisy 2048 px sheet peaks near 115 MB and a 4000 px one near 230 MB (a fatal on a
        // busier sheet). Image AIs draw 1.5k-2k px sheets, so 2048 / 4.2 MP leaves ample headroom.
        'max_side' => 2048,
        'max_pixels' => 4_194_304,
        // The filesystem disk the strips live on. Served from the app's own origin ('public') they are a path;
        // on a disk with another host (a bucket, a CDN) the fighter payload carries a full URL instead.
        // Changing it after fighters exist points the app at an empty place: run
        // custom-characters:copy-disk <from> <to> first.
        'disk' => env('TOKEN_SLAYER_CUSTOM_CHARACTER_DISK', 'public'),
        // How many confirmed versions of a fighter a player keeps to switch back to.
        'max_versions' => 5,
        // How long a second build by the same player waits for the first before being turned away.
        'lock_seconds' => 10,
        // Hold must outlast the slowest build, including storage calls and their timeout; the store aborts
        // before this deadline so another request cannot enter while the first is still deleting files.
        'lock_hold_seconds' => 150,
        // How long a built-but-unused draft (and its staged files) is kept before custom-characters:prune-drafts removes it.
        'draft_ttl_hours' => 24,
        // What a fighter is called until its player names it, and the longest name.
        'default_name' => 'Custom',
        'name_max' => 24,
        // Uploads a user may attempt per hour; building a sheet costs about a second of CPU.
        'uploads_per_hour' => 10,
        // How many custom minions a player may hold at once; each subagent they dispatch summons one of them at random.
        'minions_max' => 3,
        // Bump when the sheet layout or the prompt below changes in a way that invalidates sheets made with the old one.
        'prompt_version' => 1,
        // The prompt template users paste into an image AI, together with a picture of their character.
        // CharacterPromptBuilder fills one copy per entry of `types` below; {LABEL}, {NOT_GENERIC}, {COSTUME},
        // {IDENTITY}, {AVOID} and {ATTACK} are replaced ({LABEL} upper-case, {label} lower-case), the COSTUME ITEMS block is left empty for the user.
        'prompt' => <<<'PROMPT'
This is my original character. Redraw the SAME character as a medieval fantasy {LABEL} in the style of a classic 16-bit pixel-art RPG.

CHARACTER TYPE: {LABEL}
The selected character type is the primary authority for costume design. Generate a visually recognizable medieval fantasy {LABEL}, {NOT_GENERIC}.

CHARACTER-TYPE-DRIVEN COSTUME RULES — MANDATORY
Before rendering the character, determine the costume, armor, equipment and weapon associated with the selected character type, and apply those class-specific design rules consistently to every pose in the sprite sheet. If a line under COSTUME ITEMS below is filled in, use it for that item; for every empty line, use the {label} default below.
For the {LABEL} class, the costume MUST include the following recognizable features:
{COSTUME}

{LABEL} VISUAL IDENTITY
{IDENTITY}
{AVOID}
Do NOT use blue overalls, jeans, hoodies, modern gloves, sneakers, modern goggles, phones, logos, text or any other modern clothing or items.

COSTUME ITEMS (optional: write what you want for any item, or leave a line empty to get the {label} default above)
- Body:
- Waist:
- Feet:
- Cape:
- Weapon:
- Hat:

SIGNATURE CHARACTER FEATURE PRESERVATION
Preserve the original character's identity throughout the costume transformation: the same fur, skin or hair colors, head and face shape, eyes, ears, body proportions, and signature features such as glasses, ears, a tail or a hairstyle. Keep any signature accessory visible in every pose, reinterpreted to fit the fantasy look (for example glasses as fantasy-crafted lenses in a metal frame with a leather strap); never remove it, replace it, or cover it with the headwear. The original colors and facial markings take precedence over the costume palette: the {label}'s class identity must come from the clothing, equipment, silhouette and weapon, not from changing the character's species or face.

COSTUME CONSISTENCY — ALL FRAMES
Design one definitive {LABEL} costume and reuse that exact costume across the entire sprite sheet. Every pose MUST keep the same armor, colors, headwear, belt, pouches, cloak, boots and weapon design. Only the pose, limb positions, weapon position and cloak movement may change to support the animation. Do not randomly redesign, replace, omit or recolor costume items between frames, and keep every class-specific feature readable at small sprite scale.

COLORS: muted, slightly desaturated medieval palette in the {label}'s own colors (see the costume above). Keep the character's own main colors recognisable but toned down. No gradients, no glow, no cast shadows, no motion lines, no impact effects, no text.

{PIXEL_ART_STYLE}

{OUTPUT}
PROMPT,
        // Pieces every prompt shares, put together by CharacterPromptBuilder. {PIXEL_ART_STYLE} is `prompt_pixel_style` with its
        // three {STYLE_*} lines taken from `prompt_style_lines` (class prompts keep the fantasy-RPG wording, Custom is neutral so a
        // character that is not fantasy is not pushed toward it); {OUTPUT} is `prompt_output` with {ATTACK} replaced by the type's own.
        'prompt_pixel_style' => <<<'STYLE'
PIXEL ART STYLE:
{STYLE_REFERENCE}
- Small, compact, chibi-like sprite proportions: a relatively large head, short torso, short limbs, and a sturdy, readable silhouette. Preserve the original character's identity while adapting the proportions to fit the reference's compact game-sprite aesthetic.
- Crisp, chunky pixel clusters with clearly visible individual square pixels. Use deliberate pixel placement rather than smooth digital painting or fine pixel noise.
- Low-resolution, grid-aligned pixel art with hard pixel edges. No anti-aliasing, no subpixel details, no smooth curves, no vector shapes, and no painterly textures.
- Limited color palette, with carefully grouped pixels and strong value contrast. Use selective highlights and dark shadow pixels to create depth, especially on armor, weapons, hair, and clothing.
- Dark navy, charcoal, and deep brown outlines, with occasional near-black pixels to define the silhouette and separate overlapping body parts.
- Simple, blocky shading using approximately 2–4 shades per material. Use small, intentional highlight clusters and compact shadow areas to suggest volume without gradients or realistic lighting.
- Classic top-down / three-quarter RPG sprite rendering, with the same slightly angled view for the idle and run poses. Keep the character's equipment and body parts visually distinct and easy to read at small sprite sizes.
- The attack and defeated poses may use a clear side profile consistent with classic 2D side-scrolling RPG sprites, but never draw the run cycle in a strict side-on profile: it keeps the idle poses' angled view.
{STYLE_EQUIPMENT}
{STYLE_FEEL}
- Every pose must look like the same unified game sprite set, with consistent pixel scale, outline thickness, palette, proportions, and detail density.
STYLE,
        'prompt_style_lines' => [
            'class' => [
                '{STYLE_REFERENCE}' => '- Authentic retro RPG sprite art, resembling character sprites from classic 16-bit fantasy dungeon-crawler and action RPG games.',
                '{STYLE_EQUIPMENT}' => '- Distinct, recognizable pixel-art equipment: blocky metal helmets, segmented armor, leather straps, tiny shields, swords, axes, staffs, and other medieval fantasy gear rendered with clean, readable pixel clusters.',
                '{STYLE_FEEL}' => '- Overall appearance should resemble a playable character sprite from a nostalgic pixel-art fantasy RPG, NOT a modern high-resolution illustration, smooth cartoon, 3D model, voxel character, or realistic human rendered with pixel filters.',
            ],
            'custom' => [
                '{STYLE_REFERENCE}' => '- Authentic retro RPG sprite art, resembling character sprites from classic 16-bit RPG and action games.',
                '{STYLE_EQUIPMENT}' => '- Distinct, recognizable pixel-art clothing, props and accessories, exactly the ones the character already has, rendered with clean, readable pixel clusters.',
                '{STYLE_FEEL}' => '- Overall appearance should resemble a playable character sprite from a nostalgic 16-bit pixel-art RPG, NOT a modern high-resolution illustration, smooth cartoon, 3D model, voxel character, or realistic human rendered with pixel filters.',
            ],
        ],
        'prompt_output' => <<<'LAYOUT'
OUTPUT: ONE image on a flat solid magenta (#FF00FF) background, no grid lines, no borders. 4 rows, all poses the same size, feet on the same baseline within a row, character facing RIGHT, with clear empty space between poses:
- Row 1: idle, standing, 4 poses.
- Row 2: run cycle (a fast run, not a walk: leaning forward, long strides, arms pumping), in the same slightly angled view as the idle poses, exactly 4 poses. The legs must clearly alternate, one step of a loop: pose 1: one leg stretched far forward and the other far back, pose 2: both feet off the ground, legs tucked under the body, pose 3: the same as pose 1 with the legs swapped, pose 4: both feet off the ground again. The arms swing opposite to the legs, and the body leans forward. No two poses may look alike.
- Row 3: {ATTACK}, exactly 3 poses (wind up, strike, follow through).
- Row 4: defeated, exactly 3 poses, eyes closed, falling then lying on the ground.
Keep every pose and everything it carries or casts (weapons, capes, braids, magic circles, bolts, swirls, sparks, projectiles) inside its own cell. Effects are welcome, but they must not reach another pose: leave at least 5% of the sheet width of clear empty space between the last pixel of one pose, effect included, and the first pixel of the next, in every row. No two poses may touch or overlap.
LAYOUT,
        // The Custom type's prompt: no class and no costume, only the retro pixel look and the output, for a character that
        // should stay as it is (an idol, a cartoon character). It has no COSTUME ITEMS block.
        'prompt_custom' => <<<'PROMPT'
This is my original character. Redraw the SAME character as a retro pixel-art game sprite in the style of a classic 16-bit RPG. Keep its design, outfit, colors and every signature feature exactly as in my picture, in every pose: do not add a costume, armor, a class, a weapon or any item it does not already have, and do not change its species, face or proportions beyond the compact game-sprite look below.

{PIXEL_ART_STYLE}

{OUTPUT}
PROMPT,
        // The character types a player can pick before getting a prompt. Each defines the class costume the prompt
        // spells out: the six `costume` items (the ones the user may override), the class's `identity` and what it
        // must NOT look like (`avoid`), how it `attack`s in row 3, and what it is not (`not_generic`).
        'types' => [
            'warrior' => [
                'label' => 'Warrior',
                'not_generic' => 'not a generic adventurer, knight, barbarian or rogue',
                'costume' => [
                    'body' => 'A reinforced brown leather jerkin under a steel half-plate cuirass over a dark red tunic, with steel pauldrons and riveted steel bracers. Practical battlefield armor, strong but not full plate.',
                    'waist' => 'A wide dark brown leather belt with a heavy iron buckle, a sword scabbard on the hip and a small coin pouch.',
                    'feet' => 'Sturdy riveted dark brown leather boots with steel shin guards. No modern shoes.',
                    'cape' => 'A short deep red cape fastened at one shoulder with a bronze clasp, with a frayed hem, kept consistent across all poses and clear of the face, hands and weapon.',
                    'weapon' => 'A broad steel sword with a straight crossguard, a leather-wrapped grip and a round pommel. The sword is the primary weapon and must read clearly at sprite scale.',
                    'hat' => 'An open-faced steel helm with a short red crest, shaped around the character\'s original ears so the ears and head silhouette stay visible.',
                ],
                'identity' => 'The final costume must immediately read as a sturdy frontline warrior through its steel-and-leather armor, red cape, broad sword and open helm. Prioritize strength and battlefield durability. Use steel grey, warm brown, deep red and small bronze accents.',
                'avoid' => 'Do NOT give the warrior a knight\'s polished full plate and tabard, a wizard\'s robe or staff, a bow, a rogue\'s hood or dagger, or a savage berserker\'s bare torso and fur.',
                'attack' => 'a powerful overhead sword slash with the warrior\'s broad sword',
            ],
            'wizard' => [
                'label' => 'Wizard',
                'not_generic' => 'not a generic adventurer, warrior, cleric or rogue',
                'costume' => [
                    'body' => 'A long layered deep indigo and midnight-blue wizard robe with gold trim and small embroidered runes, a short padded mantle over the shoulders and wide cuffed sleeves. No armor.',
                    'waist' => 'A braided rope belt with a hanging leather spellbook, a small spell pouch and a glass potion vial.',
                    'feet' => 'Soft dark brown leather boots with slightly curled toes, partly hidden by the robe hem. No modern shoes.',
                    'cape' => 'A short flowing midnight-purple mantle speckled with tiny gold stars, attached at the shoulders and kept consistent across all poses.',
                    'weapon' => 'A gnarled wooden staff taller than the character, topped with a glowing blue-white crystal held in a gold claw. The staff is the primary weapon and must read clearly at sprite scale.',
                    'hat' => 'A tall pointed indigo wizard hat with a wide bent brim and a gold band, shaped so the character\'s original ears stay visible beside or through it.',
                ],
                'identity' => 'The final costume must immediately read as an arcane wizard through its long robe, star-speckled mantle, pointed hat and crystal staff. Prioritize flowing cloth and magical detail over protection. Use indigo, midnight purple, gold trim and a cool crystal glow limited to the staff head.',
                'avoid' => 'Do NOT give the wizard metal armor, a sword, a shield, a bow, a hood or a rogue\'s leather outfit, or a plain generic cloak.',
                'attack' => 'a staff swing that casts a magic bolt from the crystal, using the wizard\'s staff',
            ],
            'assassin' => [
                'label' => 'Assassin',
                'not_generic' => 'not a generic adventurer, warrior, rogue or knight',
                'costume' => [
                    'body' => 'Dark charcoal and deep forest-green fitted leather assassin armor, with lightweight segmented leather shoulder guards, a dark hood or cowl, and a short face scarf if it does not obscure the character\'s signature facial features.',
                    'waist' => 'A dark brown utility belt with small pouches, a visible dagger sheath and narrow leather straps. Keep the silhouette compact and practical.',
                    'feet' => 'Dark brown or charcoal lightweight leather boots with simple reinforced toes and minimal armor. No modern shoes.',
                    'cape' => 'A short, ragged-edged deep burgundy or dark crimson assassin cloak, attached at the shoulders. The cloak must remain consistent across all poses and must not obscure the face, hands or weapon.',
                    'weapon' => 'A clearly visible short assassin dagger with a pointed silver-steel blade, a dark crossguard and a brown leather-wrapped handle. The dagger is the primary weapon and must be recognizable at sprite scale.',
                    'hat' => 'A dark charcoal assassin hood or close-fitting cowl, adapted around the character\'s original ears. The hood must not replace, hide or change the character\'s signature ears or head silhouette.',
                ],
                'identity' => 'The final costume must immediately read as a stealth-oriented fantasy assassin through its dark fitted leather armor, utility belt, dagger, lightweight boots and short dark cloak. Prioritize agility, concealment and lightweight equipment rather than heavy protection. Use charcoal, muted forest green, dark brown, deep burgundy and small dull-steel accents.',
                'avoid' => 'Do NOT give the assassin a knight\'s full plate armor, large shoulder armor, a shield, a wizard\'s robe, a mage\'s staff, a ranger\'s bow as the primary weapon, or a generic brightly colored adventurer outfit.',
                'attack' => 'a quick forward dagger stab with the assassin\'s dagger',
            ],
            'archer' => [
                'label' => 'Archer',
                'not_generic' => 'not a generic adventurer, warrior, rogue or hunter in modern gear',
                'costume' => [
                    'body' => 'A forest-green leather jerkin over a cream linen shirt with rolled sleeves, a leather chest strap carrying the quiver, and leather bracers on both forearms. Light, mobile armor.',
                    'waist' => 'A brown leather belt with a small hunting knife, an arrow pouch and a waterskin.',
                    'feet' => 'Tall soft brown leather boots laced up the shin. No modern shoes.',
                    'cape' => 'A short moss-green traveling cloak fastened at the throat, with a worn hem, kept consistent across all poses and clear of the bow arm.',
                    'weapon' => 'A tall wooden longbow with a visible string and leather grip, plus a quiver of fletched arrows on the back. The bow is the primary weapon and must read clearly at sprite scale.',
                    'hat' => 'A green ranger cap or soft hood with one feather, shaped around the character\'s original ears so the ears and head silhouette stay visible.',
                ],
                'identity' => 'The final costume must immediately read as a fantasy archer through its green leather jerkin, quiver, longbow and feathered cap. Prioritize light, mobile woodland gear rather than heavy protection. Use forest green, moss, cream, warm brown and small steel accents.',
                'avoid' => 'Do NOT give the archer heavy plate armor, a shield, a sword as the primary weapon, a wizard\'s robe or staff, a dark assassin hood, or a modern hunting outfit.',
                'attack' => 'drawing the longbow and loosing an arrow',
            ],
            'healer' => [
                'label' => 'Healer',
                'not_generic' => 'not a generic adventurer, wizard, warrior or necromancer',
                'costume' => [
                    'body' => 'A white and cream cleric robe with gold trim and a small golden sun emblem on the chest, with a light padded mantle over the shoulders and long soft sleeves. No heavy armor.',
                    'waist' => 'A gold-and-cream cloth sash tied at the waist, with a small herb pouch and a corked glass healing vial.',
                    'feet' => 'Simple cream and light brown leather boots or sandals wrapped with cloth. No modern shoes.',
                    'cape' => 'A short white mantle edged in gold, attached at the shoulders and kept consistent across all poses, clear of the face and staff hand.',
                    'weapon' => 'An ornate wooden holy staff topped with a golden sun ring and a soft warm glow limited to the ring. The staff is the primary weapon and must read clearly at sprite scale.',
                    'hat' => 'A white and gold cloth hood or simple golden circlet, adapted around the character\'s original ears so the ears and head silhouette stay visible.',
                ],
                'identity' => 'The final costume must immediately read as a gentle fantasy healer through its white-and-gold robe, sun emblem, healing vial and holy staff. Prioritize light, clean, protective cloth rather than armor. Use white, cream, soft gold and a touch of light brown.',
                'avoid' => 'Do NOT give the healer dark or black clothing, bone or skull motifs, heavy plate armor, a sword, a bow, or a necromancer\'s or wizard\'s dark robe.',
                'attack' => 'a swing of the holy staff releasing a small burst of light from the sun ring',
            ],
            'necromancer' => [
                'label' => 'Necromancer',
                'not_generic' => 'not a generic adventurer, wizard, cleric or rogue',
                'costume' => [
                    'body' => 'Tattered black and deep purple robes over a bone-white ribcage-patterned leather vest, with small bone shoulder plates and a skull clasp at the collar. Dark and ragged, not armored.',
                    'waist' => 'A belt of black leather and bone links with a hanging skull charm, a small grimoire and a corked vial of sickly green liquid.',
                    'feet' => 'Black wrapped cloth boots with bone toe caps and frayed straps. No modern shoes.',
                    'cape' => 'A ragged dark violet cloak with a torn, uneven hem, attached at the shoulders and kept consistent across all poses and clear of the face and staff hand.',
                    'weapon' => 'A twisted dark-wood staff topped with a small skull gripping a sickly green crystal. The staff is the primary weapon and must read clearly at sprite scale.',
                    'hat' => 'A dark violet cowl with a thin bone circlet, adapted around the character\'s original ears so the ears and head silhouette stay visible.',
                ],
                'identity' => 'The final costume must immediately read as a sinister fantasy necromancer through its tattered black-and-violet robes, bone details, skull staff and ragged cloak. Prioritize an eerie, decayed look rather than protection. Use black, deep violet, bone white and sickly green accents limited to the crystal and vial.',
                'avoid' => 'Do NOT give the necromancer bright holy white or gold robes, knight\'s plate armor, a bow, a sword, or a cheerful wizard\'s pointed hat and stars.',
                'attack' => 'a staff thrust that casts a dark bolt from the green crystal, using the necromancer\'s skull staff',
            ],
            'alchemist' => [
                'label' => 'Alchemist',
                'not_generic' => 'not a generic adventurer, wizard, rogue or modern scientist',
                'costume' => [
                    'body' => 'A teal-green and mustard shirt with rolled sleeves under a heavy brown leather apron stained with chemicals, thick brown leather gauntlets and small brass buttons. Medieval workshop wear, not armor.',
                    'waist' => 'A leather bandolier and belt crowded with corked glass vials and flasks of different colored liquids, plus small pouches and tongs.',
                    'feet' => 'Sturdy dark brown boots with buckled leather spats. No modern shoes.',
                    'cape' => 'A short ochre-yellow utility mantle or scarf tied at the neck, with scorched edges, kept consistent across all poses and clear of the hands.',
                    'weapon' => 'A large round glass flask of bubbling green liquid held in one hand, ready to be thrown, with a cork stopper and a brass cap. The flask is the primary weapon and must read clearly at sprite scale.',
                    'hat' => 'A brown leather cap with a short brim, shaped around the character\'s original ears so the ears and head silhouette stay visible.',
                ],
                'identity' => 'The final costume must immediately read as a fantasy alchemist through its stained leather apron, vial-covered bandolier, gauntlets and bubbling potion flask. Prioritize a crafty workshop look rather than protection. Use leather brown, teal, mustard and bright potion green limited to the liquids.',
                'avoid' => 'Do NOT give the alchemist a wizard\'s robe or hat, plate armor, a sword, a bow, a modern white lab coat or modern safety goggles.',
                'attack' => 'a forward overhand throw of the alchemist\'s potion flask',
            ],
            'berserker' => [
                'label' => 'Berserker',
                'not_generic' => 'not a generic adventurer, warrior, knight or barbarian king',
                'costume' => [
                    'body' => 'A rough fur-trimmed leather harness crossed over the chest on an otherwise bare torso (showing the character\'s own fur or skin), with wrapped leather arm guards and red war-paint stripes. Minimal, savage and ragged, not armor plates.',
                    'waist' => 'A heavy studded dark leather belt holding an animal-pelt kilt, with small bone charms and a coiled rope.',
                    'feet' => 'Fur-lined, cloth-wrapped leather boots tied with rough cord. No modern shoes.',
                    'cape' => 'A shaggy wolf-pelt mantle draped over the shoulders and fastened with a bone pin, kept consistent across all poses and clear of the face and axe hand.',
                    'weapon' => 'A huge double-bladed battle axe with a rough dark-iron head and a wrapped wooden haft. The axe is the primary weapon and must read clearly at sprite scale.',
                    'hat' => 'A dented iron helm with a pair of curved horns and a fur lining, shaped around the character\'s original ears so the ears and head silhouette stay visible.',
                ],
                'identity' => 'The final costume must immediately read as a ferocious fantasy berserker through its fur and leather harness, wolf-pelt mantle, horned helm and huge battle axe. Prioritize raw strength and ferocity over protection. Use rust red, dark iron, rough brown fur and bone-white accents.',
                'avoid' => 'Do NOT give the berserker polished plate armor, tidy robes, a hood, a bow, a wizard\'s staff, or refined, neat gear.',
                'attack' => 'a wild two-handed sweeping axe chop with the berserker\'s battle axe',
            ],
            'custom' => [
                'label' => 'Custom',
                'template' => 'custom',
                'attack' => 'a simple, readable attack that suits the character, using only what it already has (a punch, a kick or a swing of an item it already holds)',
            ],
        ],
        // Height in px the idle/walk poses are shrunk to, before the outline is added. The field draws the fighter at
        // 22 (the roster's bodies are 18). Measured on a real AI sheet: at 22 the costume averaged into brown mush, at 32
        // the face survived and the costume did not, at 44 face, armour and tunic read, at 52 a little more. The 100 px frame
        // holds about 56 above the foot row, so 66 or 88 would be squeezed back to that.
        // JS twin: CUSTOM_BODY_PX in fighter/custom-fighter.js.
        'sprite_height' => 52,
        // Colours shared by every pose after quantising.
        'palette_colors' => 64,
        'outline_color' => '#16121f',
        // How far an idle/walk pose's height may stray from the median, as a fraction of it.
        'size_tolerance' => 0.3,
        // A detached ink blob smaller than this share of its pose's ink is a stray mark (motion lines, sparks).
        'stray_ratio' => 0.03,
        // Multipliers pulling an uploaded character's colours toward the roster's muted look. 1 keeps the sheet's own
        // colours: the player chose them, and a pull toward muted turned gold fur and a red tunic to tan and brown.
        'tone_saturation' => 1.0,
        'tone_lightness' => 1.0,
        // Rows top to bottom, each with the pose count it must have.
        'rows' => [
            ['name' => 'idle', 'min' => 2, 'max' => 4],
            ['name' => 'walk', 'min' => 4, 'max' => 4],
            ['name' => 'attack', 'min' => 3, 'max' => 3],
            ['name' => 'death', 'min' => 3, 'max' => 3],
        ],
    ],

];
