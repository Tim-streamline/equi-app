"""Render metadata exported by export-schema.php. Requires Graphviz, no Python packages.
Usage: python3 docs/model-tools/render-model.py /tmp/equi-schema.json
"""
import html
import json
from pathlib import Path
import subprocess
import sys

schema = json.loads(Path(sys.argv[1]).read_text())
out = Path(__file__).resolve().parents[1]
groups = {
    'Accounts and horses': 'users horses horse_shares horse_stats observations observation_photos timeline_events account_settings user_home_preferences notification_preferences pending_registrations data_exports',
    'Protocols and intake': 'protocols intake_bookings intake_answers intake_attachments protocol_analyses protocol_advice protocol_phases protocol_phase_weeks protocol_phase_supplements protocol_phase_supplement_weeks protocol_supplement_intakes protocol_day_snapshots protocol_weekly_updates',
    'Clinical templates and questionnaire': 'protocol_templates protocol_template_phases protocol_template_phase_weeks supplements supplement_weeks voeding_adviezen management_adviezen beweging_adviezen protocol_voeding_adviezen protocol_management_adviezen protocol_beweging_adviezen intake_questionnaires intake_sections intake_fields therapists',
    'Plans payments and credits': 'plans plan_benefits subscriptions payments protocol_purchases plus_intake_entitlements plus_upgrades plus_pages credit_bundles credit_grants credit_orders credit_reminders credit_settings credit_transactions library_credit_balances library_unlocks',
    'Library': 'library_items library_contents library_categories library_item_categories library_chapters library_article_sections library_attachments library_item_access library_bookmarks library_progress seasonal_tips',
    'Community and moderation': 'community_posts community_replies community_reactions community_categories community_tags community_post_tags community_media community_bookmarks community_mutes moderation_reports user_restrictions',
    'Products scans and chat': 'products ingredients ingredient_product scan_results scan_ingredients chat_sessions chat_messages nova_fallback_replies',
    'Notifications administration and infrastructure': 'web_push_subscriptions intake_reminder_deliveries protocol_phase_reminders browser_reminder_deliveries admin_users audit_logs media_assets sessions password_reset_tokens cache cache_locks jobs job_batches failed_jobs migrations',
}
groups = {k: v.split() for k, v in groups.items()}
listed = [t for ts in groups.values() for t in ts]
assert len(set(listed)) == len(listed)
assert set(listed) == set(schema), (set(schema)-set(listed), set(listed)-set(schema))
def esc(s): return html.escape(str(s), quote=True)
def slug(s): return s.lower().replace(' ', '-')
def fks(t):
    # The composite FK reinforces the same relationship; document its full definition below.
    return [f for f in schema[t]['foreignKeys'] if f['name'] != 'intake_protocol_horse_fk']
def keymarks(t, name):
    marks = []
    if any(i['primary'] and name in i['columns'] for i in schema[t]['indexes']): marks.append('PK')
    if any(name in f['columns'] for f in schema[t]['foreignKeys']): marks.append('FK')
    if any(i['unique'] and not i['primary'] and i['columns'] == [name] and i['name'] != 'protocols_one_active_per_horse' for i in schema[t]['indexes']): marks.append('UQ')
    return ', '.join(marks)
def cardinality(t, f):
    nullable = any(c['nullable'] for c in schema[t]['columns'] if c['name'] in f['columns'])
    unique = any(i['unique'] and set(i['columns']) == set(f['columns']) and i['name'] != 'protocols_one_active_per_horse' for i in schema[t]['indexes'])
    return ('0..1' if nullable else '1', '1' if t == 'intake_bookings' and f['foreign_table'] == 'protocols' else ('0..1' if unique else '0..*'))

sections = []
md = ['# EquiApp full data model', '', f'Schema snapshot after the 2026-10-15 migrations: **{len(schema)} tables**. Metadata only, no application data. Verified against the migrated test database; existing environments migrate during deployment.', '', 'Every table is listed once in its domain. External parent tables reappear in diagrams as references. PK = primary key, FK = foreign key, UQ = single-column unique constraint. `protocols.horse_id` is unique only where status is active. The protocol/intake relationship is exactly 1:1, enforced by a required unique FK plus deferred triggers. Other edges reflect declared database foreign keys. Polymorphic and JSON references are described separately below.', '']
for title, tables in groups.items():
    externals = sorted({f['foreign_table'] for t in tables for f in fks(t)} - set(tables))
    dot = ['digraph G {', 'graph [rankdir=LR, bgcolor="transparent", pad="0.3", nodesep="0.3", ranksep="1.1", fontname="Arial"];', 'node [shape=plain, fontname="Arial", fontsize=12];', 'edge [color="#738b88", fontname="Arial", fontsize=10, arrowsize=0.65];']
    mermaid = ['erDiagram']
    for t in tables + externals:
        rows = [f'<TR><TD BGCOLOR="{("#dcece8" if t in tables else "#f1f3f2")}"><B>{t}</B>{" (reference)" if t in externals else ""}</TD></TR>']
        if t in tables:
            for c in schema[t]['columns']:
                marks = keymarks(t,c['name'])
                if marks:
                    rows.append(f'<TR><TD ALIGN="LEFT">{esc(c["name"])}  [{marks}]</TD></TR>')
        dot.append(f'"{t}" [URL="#table-{t}", label=<<TABLE BORDER="0" CELLBORDER="1" CELLSPACING="0" CELLPADDING="7" COLOR="#c7d6d2">{"".join(rows)}</TABLE>>];')
        if t in tables:
            mermaid.append(f'    {t} {{')
            for c in schema[t]['columns']:
                marks=keymarks(t,c['name']).replace('UQ','UK')
                typ=c['type_name'].replace(' ','_').replace('[]','_array')
                mermaid.append(f'        {typ} {c["name"]}'+(' '+marks if marks else ''))
            mermaid.append('    }')
    for t in tables:
        for f in fks(t):
            parent=f['foreign_table']; left,right=cardinality(t,f)
            label=', '.join(f['columns'])
            dot.append(f'"{parent}" -> "{t}" [label="{label}", taillabel="{left}", headlabel="{right}"];')
            mermaid.append(f'    {parent} '+('o|' if left=='0..1' else '||')+'--'+('||' if right=='1' else ('o|' if right=='0..1' else 'o{'))+f' {t} : "{label}"')
    dot.append('}')
    svg = subprocess.check_output(['dot','-Tsvg'], input='\n'.join(dot).encode()).decode()
    svg=svg[svg.index('<svg'):].replace('<svg ', '<svg role="img" aria-label="'+esc(title)+' relationships" ',1)
    details=[]
    md += ['## '+title, '', '```mermaid', *mermaid, '```', '']
    for t in tables:
        rows=[]; md+=['### '+t, '', '| Column | Type | Nullable | Key |', '|---|---|---|---|']
        for c in schema[t]['columns']:
            vals=[c['name'],c['type'], 'Yes' if c['nullable'] else 'No',keymarks(t,c['name'])]
            rows.append('<tr>'+''.join('<td>'+esc(v)+'</td>' for v in vals)+'</tr>')
            md.append('| '+' | '.join(vals)+' |')
        refs=[]
        for f in schema[t]['foreignKeys']:
            txt=f'{", ".join(f["columns"])} → {f["foreign_table"]}({", ".join(f["foreign_columns"])}) · on delete: {f["on_delete"]}'
            refs.append('<li>'+esc(txt)+'</li>')
            md+=['', '- '+txt]
        details.append(f'<details id="table-{t}"><summary>{t} <span>{len(rows)} columns</span></summary><div class="table-wrap"><table><thead><tr><th>Column</th><th>Type</th><th>Nullable</th><th>Key</th></tr></thead><tbody>{"".join(rows)}</tbody></table></div><ul>{"".join(refs)}</ul></details>')
        md.append('')
    sections.append(f'<section id="{slug(title)}"><h2>{title}</h2><div class="diagram">{svg}</div><div class="definitions">{"".join(details)}</div><a href="#top">Back to overview</a></section>')

notes='''<section id="logical"><h2>Application-level references</h2><p>These links are not database foreign keys. Legacy entitlement <code>booking_id</code> retains the consumed intake ID even after deletion. Intake answer <code>section_id</code> and <code>field_id</code> are stable questionnaire keys, not UUID foreign keys. A moved question uses <code>intake_fields.answer_section_key</code> to keep its original answer and attachment namespace while its <code>section_id</code> selects the editable presentation section. Polymorphic references (such as audit targets, moderation subjects and media owners) resolve according to their type fields. JSON columns store structured answers, snapshots, layouts or settings; their internal fields are not separate relational tables. The PHP models and PowerSync schema expose portions of this authoritative PostgreSQL model.</p><p>The conditional unique index permits one active protocol per horse; completed, paused, draft and archived history is not subject to that index. The composite intake FK enforces the same horse on both sides, and deferred triggers enforce a required intake at transaction commit.</p></section>'''
page='''<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>EquiApp — full data model</title><style>
:root{font-family:system-ui,sans-serif;color:#183b37;background:#f7faf8;line-height:1.5}body{margin:0;padding:32px;max-width:1600px;margin-inline:auto}h1{font-size:32px;margin-bottom:8px}h2{font-size:24px}p{max-width:900px}a{color:#08766c}nav{display:flex;flex-wrap:wrap;gap:10px 24px;margin:28px 0}section{margin-block:48px}.diagram{background:white;border:1px solid #dce6e0;border-radius:12px;overflow:auto;padding:16px}.diagram svg{min-width:750px;max-width:none;height:auto;display:block}details{border-bottom:1px solid #dce6e0;padding:12px 0;scroll-margin-top:20px}summary{cursor:pointer;font-weight:600}summary span{font-weight:400;color:#5d7670;margin-left:12px}table{border-collapse:collapse;width:100%;font-size:14px}th,td{text-align:left;padding:8px;border-bottom:1px solid #e5ece8}code{font-size:0.95em}.table-wrap{overflow:auto}header small{color:#5d7670}.rule{border-left:4px solid #08766c;padding-left:16px}@media(max-width:600px){body{padding:16px}h1{font-size:26px}}
</style><header id="top"><small>EquiApp · PostgreSQL schema · 15 October 2026</small><h1>Full data model</h1><p>TABLE_COUNT tables, grouped by domain. Diagrams show primary and foreign keys; expand a table below each diagram for every column and deletion rule. Grey reference nodes belong to another domain. Large diagrams can be scrolled horizontally.</p><p class="rule"><strong>Horse → protocol → intake:</strong> a horse has a history of protocols, at most one active at a time. Every protocol has exactly one intake for that horse. One subscription unlocks app features; protocols are separate purchases, each creating its own intake. Library credits remain separate.</p><p>This is the implemented target schema, verified in the test database. Existing databases must pass the legacy-data audit before enforcement.</p></header>'''
page=page.replace('TABLE_COUNT', str(len(schema)))
page+='<nav aria-label="Domains">'+''.join(f'<a href="#{slug(t)}">{t} ({len(ts)})</a>' for t,ts in groups.items())+'<a href="#logical">Application-level references</a></nav>'
page+=''.join(sections)+notes+'''<script>
document.addEventListener('click', event => { const a=event.target.closest('a'); const href=a?.getAttribute('href') ?? a?.getAttribute('xlink:href'); if(href?.startsWith('#table-')){ const target=document.getElementById(href.slice(1)); if(target) target.open=true; }});
</script></html>'''
(out/'data-model.html').write_text(page)
md+=['## Application-level references','','Legacy entitlement `booking_id` deliberately retains the consumed intake identifier without a foreign key, including after deletion. Intake answer `section_id` and `field_id` refer to stable questionnaire keys. Polymorphic type/id pairs and JSON payloads resolve in application code; they are not shown as enforced database relationships.','']
(out/'data-model.md').write_text('\n'.join(md))
print(f'Rendered {len(schema)} tables and {sum(len(fks(t)) for t in schema)} foreign-key edges in {len(groups)} diagrams.')
