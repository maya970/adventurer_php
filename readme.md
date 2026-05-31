冒险者地牢 · 功能与开发索引
================================
（纯文本，供开发查阅；玩家界面无此页）

【核心流程】
登录 login.html
  API: session, login, register
  文件: js/auth/login-page.js, api.php

主城 town.html
  API: player, dungeon_prepare, auto_actions_set
  文件: js/town/town-page.js, js/town/town-ui.js, js/town/town-labels.js
  说明: 属性/背包/装备/称号；底部进入地下城、PK、分支地牢

地下城 dungeon.html
  API: player, dungeon_save, battle_deck_consume_potion
  文件: js/dungeon/dungeon-game.js, js/common/battle-deck.js, data/skills.json
  说明: 牌组满18张用手牌战斗；技能书掉落；存档回城写入收益

【角色成长】
职业与技能 profession.html
  API: profession_set, skill_enhance_preview, skill_enhance, skills_learn_book
  文件: js/town/profession-page.js, php/common/skill_system.php
  说明: 体修/灵修锁定；技能强化一次/连续强化；技能书在背包使用

战斗牌组 deck.html
  API: battle_deck_get, battle_deck_set
  文件: js/town/deck-page.js, php/town/battle_deck.php
  说明: 12技能+6药水；地下城/PK用手牌

强化工坊 enhance.html
  API: enhance_preview, enhance
  文件: js/town/enhance-page.js
  说明: 装备+0~+20；连续强化直到成功或金币不足

杂货店 shop.html
  API: shop_catalog, shop_buy_item（支持 quantity 数量）
  文件: js/town/shop-page.js, php/town/general_shop.php
  说明: 斧子/药水等可带入分支地牢行囊

【分支地牢】
分支地牢 surface-branch.html
  API:
    branch_dungeon_enter      进入/恢复存档
    branch_dungeon_loadout    准备页：按种类统计背包数量
    branch_dungeon_start      开始（carry_counts: { item_key: n }）
    surface_branch_resolve_room  处理当前房间
    surface_branch_choose     选门（含层数跳跃）
    branch_dungeon_save       存档回城（行囊保留）
    branch_dungeon_finish     mode=claim 安全层取回行囊 / mode=retreat 无功而返
  文件: js/branch/branch-page.js, php/common/expansion_systems.php
  说明:
    - 准备：按物品种类填数量，从背包移入行囊（非复制）
    - 三扇门：1捷径可多层（极少数+10~16），2扇+1~3层
    - 安全撤离：50/100/150…固定层 + 随机安全层 → 可结束冒险归还行囊
    - 非安全层：仅「无功而返」（行囊战利品不带回）
    - 死亡：行囊清空

【社交与其它】
拍卖行 auction.html     API: auction_list, auction_post, auction_buy
公会 guild.html          API: guild_*
排行榜 leaderboard.html  API: leaderboard
匹配 PK pk.html          API: match_pk_*, battle_deck_consume_potion
魔物图鉴 codex.html      data/monsters.json
跳跃法阵 jump.html
生命教会 life.html
酒馆 tavern.html
皮肤 character.html

【后端】
API 总线: api.php（POST JSON，action=…）
  核心: php/common/bootstrap.php, php/common/expansion_systems.php, php/common/skill_system.php
  配置: config.php（app_build 变更后客户端自动清缓存）

player 接口返回: player, inventory, skills, battle_deck, auto_action_preset 等

【缓存】
修改 JS/CSS/HTML 后请递增 config.php 中 app_build，登录后会触发 rpg-build-check 刷新。
