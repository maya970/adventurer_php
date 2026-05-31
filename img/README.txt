Place optional local assets here (game tries these before Arweave):

  monsters/0001.gif, 0002.gif, … (png/jpg/webp 同名也可)
    — 每只怪会带一个循环序号，优先加载对应编号；仍可按图鉴 key 回退。

  items/0001.gif, 0002.gif, …
    — 宝箱精灵按槽位序号尝试；失败则试 0001–0064 再回退旧文件名。

  photo/0001.webp（或 .png / .jpg / .gif）— 分支地牢遭遇插图，路径固定为项目根下 <strong>img/photo/</strong>，四位编号与 data/branch_encounters.json 的 image_num 一致（0001～0019 已绑定各遭遇）。

  tiles/0001.gif — 地板（第 1–10 层档）
      0002.gif — 墙
      0003.gif — 天花板
      每 10 层下一组：0004/0005/0006 …（与客户端 tileIndicesForFloor 一致）
      仅加载主编号文件，不再尝试 0001_1 这类带下划后缀的变体。
      楼梯/传送门另试 tiles/0000.*
    — 再试远程贴图兜底。

若文件缺失，使用程序生成的色块与 data/monsters.json 里的远程 sprite。
