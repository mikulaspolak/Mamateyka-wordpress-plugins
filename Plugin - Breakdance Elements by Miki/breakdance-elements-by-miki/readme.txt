=== Breakdance Elements by Miki ===
Contributors: mikulaspolak
Requires at least: 6.0
Requires PHP: 8.2
Stable tag: 1.15.0
License: Proprietary - All rights reserved

Vlastné Breakdance prvky vytvorené v Breakdance Element Studio alebo ručne. Všetky sa v paneli pridávania prvkov zobrazujú v samostatnej sekcii **Elements by Miki**.

== Inštalácia ==

1. Vo WordPresse otvor Pluginy -> Pridať nový plugin -> Nahrať plugin.
2. Nahraj ZIP vytvorený skriptom build-release.ps1 a plugin aktivuj (vyžaduje aktívny Breakdance).
3. Otvor stránku v Breakdance, obnov editor a v paneli pridávania prvkov nájdi sekciu Elements by Miki.
4. Nové elementy cez Element Studio: New element -> umiestnenie Miki Elements. Nevyberaj priečinok Breakdance, pretože aktualizácia Breakdance môže jeho súbory prepísať.

== Element: Memories (Spomienky) ==

Mriežka kariet so spomienkami (nadpis sekcie a odkaz „Ďalšie spomienky“ si pridaj zvlášť ako bežné prvky Breakdance).

- Repeater kariet: obrázok (+ voliteľný alt), avatar autora, autor, nadpis, farebný štítok, voliteľný citát a text.
- Bez avatara sa zobrazí kruh s iniciálou autora. Bez obrázka sa obrázkový blok nezobrazí.
- Alt obrázka: vlastný alt z karty, inak alt z knižnice médií, inak prázdny (dekoratívny obrázok).
- Najviac 99 kariet; Breakdance neponúka UI limit pre repeater, preto je v editore upozornenie a limit vynucuje šablóna.
- Masonry cez CSS columns, poradie pokračuje zhora nadol v stĺpcoch. Režim stĺpcov: automatický (minimálna šírka karty) alebo manuálny (počet stĺpcov); obe hodnoty sú responzívne podľa breakpointov Breakdance.
- Dizajn: pozadie, farby, zaoblenie, počet riadkov textu; prepínače pre náklon kariet, lepiacu pásku a jemný hover (bez JavaScriptu, rešpektuje `prefers-reduced-motion`).

== Aktualizácie Breakdance a WordPressu ==

- Plugin je samostatný priečinok `wp-content/plugins/breakdance-elements-by-miki/`. Aktualizácia Breakdance ani WordPressu ho neprepíše; prepísal by ho len Breakdance priečinok, preto elementy nikdy neukladaj do umiestnenia Breakdance.
- Obsah stránok je uložený v databáze. Ukladá sa slug elementu (`BreakdanceElementsByMiki\MemoriesSpomienky`) a cesty polí (napr. `content.memories.items[].heading`). **Tieto slugy ani cesty nikdy nemeň ani nepremenúvaj**, inak stránky stratia obsah. Názov v paneli, texty polí a CSS sa meniť môžu. Nové polia pridávaj len ako nepovinné s bezpečnou predvolenou hodnotou.
- Ak plugin deaktivuješ alebo zmažeš, elementy na stránkach sa zobrazia ako chýbajúce (dáta v databáze ostanú a po opätovnej aktivácii sa vrátia).
- Po nahratí novej verzie ZIPu vyprázdni/regeneruj cache CSS v nastaveniach Breakdance (presný názov možnosti over v 2.8.3) a cache stránky/CDN, aby sa načítal nový CSS elementu.
- Zdrojom pravdy je repozitár. ZIP prepíše celý priečinok pluginu, preto zmeny z Element Studio na serveri najprv stiahni do repozitára, inak sa stratia.
- Po väčšej aktualizácii Breakdance (nová hlavná verzia) plugin zobrazí upozornenie; skontroluj elementy v editore a na fronte. Plugin používa len stabilné časti API (`Element`, `c()`, `registerCategory`, `registerSaveLocation`) a pri chýbajúcom Breakdance sa nenačíta.
- Pred aktualizáciou Breakdance urob zálohu databázy a súborov.

== Preklady ==

Texty v editore sú v kóde po anglicky cez `t('...')`. Slovenčina (podľa jazyka používateľa v editore) je v `languages/sk_SK.php`. Kľúč musí byť presne rovnaký ako anglický text. Názov elementu: Memories (sk: Spomienky).

== Pridanie ďalšieho elementu ==

Každý element je samostatný priečinok `elements/<Názov>/` (`element.php`, `html.twig`, `css.twig`, `default.css`) a nesmie závisieť od iných elementov. Breakdance ho načíta automaticky, nič sa neregistruje centrálne. Použi kostru z `Templates/Element Skeleton/` (nie je súčasťou ZIPu), nastav `category()` na `miki-elements`, vlastný CSS prefix `miki-<názov>__` a unikátnu triedu/slug. Pred zmenami v Element Studio urob commit alebo zálohu; tlačidlo Delete v Studiu súbory odstráni.

== Vývoj a vydanie ==

Breakdance Element Studio zapisuje elementy do `elements/`, makrá do `macros/` a presety do `presets/`. Vydanie vytvoríš spustením `build-release.ps1` z priečinka projektu. Skript automaticky zvýši minor verziu a nastaví patch na nulu v hlavnom PHP súbore aj `Stable tag` v tomto súbore; výsledný ZIP bude v `Releases/`.

Cieľ: Breakdance 2.8.3, WordPress 7.1.4, PHP 8.2.


== Changelog ==

= 1.0.0 =
- Základný plugin a Element Studio umiestnenia pre prvky, makrá a presety.
= 1.1.0 =
- Prvý hotový element Memories - Spomienky.
= 1.2.0 =
- Samostatná sekcia Elements by Miki v paneli prvkov.
- Memories - Spomienky prepracovaný: funkčné načítanie šablón a CSS, registrácia v Element Studio, ikona, karty s avatarom, štítkom, náklonom a páskou, opravená schéma dropdownu a responzívnych polí.
= 1.3.0 =
- Element sa volá Memories (sk: Spomienky); texty v editore sú po anglicky so slovenským prekladom v pluguine.
- Upozornenie po aktualizácii na novú hlavnú verziu Breakdance, dokumentácia aktualizácií.