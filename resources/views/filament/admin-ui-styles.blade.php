<style>
.admin-ui{--admin-accent:var(--admin-ui-accent);--admin-bg:var(--admin-ui-surface);--admin-soft:var(--admin-ui-subtle);--admin-text:var(--admin-ui-text);--admin-muted:var(--admin-ui-muted);--admin-border:var(--admin-ui-border);--admin-focus:var(--admin-ui-focus);color:var(--admin-text);font-size:var(--admin-ui-font-size);line-height:1.65;display:grid;grid-template-columns:minmax(0,1fr);gap:20px;min-width:0;width:100%}
.admin-ui *, .admin-ui *:before,.admin-ui *:after{box-sizing:border-box}
.admin-ui h2,.admin-ui h3,.admin-ui p{margin:0}
.admin-ui-heading{display:flex;justify-content:space-between;align-items:flex-start;gap:20px}
.admin-ui-heading>div,.admin-ui-card-heading>div{min-width:0;max-width:100%}
.admin-ui-heading h2,.admin-ui-card-heading h2{overflow-wrap:anywhere}
.admin-ui-heading h2{font-size:18px;font-weight:600}
.admin-ui-heading p,.admin-ui-card-heading p,.admin-ui-dialog-heading p{font-size:12px;color:var(--admin-muted);margin-top:5px;max-width:760px}
.admin-workspace-header{align-items:center;gap:16px;padding:0 0 16px;border-bottom:1px solid var(--admin-border);background:transparent}
.admin-workspace-identity{flex:1;min-width:0}
.admin-workspace-title{display:flex;align-items:center;gap:8px;flex-wrap:wrap;min-width:0}
.admin-workspace-id{font-size:12px;color:var(--admin-muted);overflow-wrap:anywhere}
.admin-workspace-header h2{font-size:20px;line-height:1.4;overflow-wrap:anywhere}
.admin-workspace-header p{margin-top:3px}
.admin-workspace-actions{justify-content:flex-end;max-width:55%;flex-shrink:0}
.admin-workspace-actions .admin-ui-button{white-space:normal;text-align:center}
.admin-workspace-tabs{display:flex;align-items:center;gap:5px 22px;flex-wrap:wrap;min-width:0;border-bottom:1px solid var(--admin-border);margin-top:-8px}
.admin-workspace-tab{display:inline-flex;align-items:center;gap:6px;padding:9px 1px 11px;color:var(--admin-muted);text-decoration:none;border-bottom:2px solid transparent;white-space:nowrap}
.admin-workspace-tab[aria-current=page]{border-bottom-color:var(--admin-accent);color:var(--admin-accent);font-weight:600}
.admin-workspace-tab:hover{color:var(--admin-accent)}
.admin-workspace-columns{display:grid;grid-template-columns:minmax(0,1fr) minmax(250px,.45fr);gap:20px;align-items:start;min-width:0}
.admin-workspace-main,.admin-workspace-rail{display:grid;gap:20px;min-width:0}
.admin-workspace-rail .admin-ui-facts{grid-template-columns:minmax(0,1fr);gap:14px}
@media(max-width:1100px){.admin-workspace-header{flex-wrap:wrap}.admin-workspace-actions{max-width:100%}}
@media(max-width:850px){.admin-workspace-columns{display:flex;flex-direction:column}.admin-workspace-main,.admin-workspace-rail{width:100%}.admin-workspace-rail{order:-1}}
@media(max-width:640px){.admin-workspace-header{padding-bottom:14px}.admin-workspace-header h2{font-size:18px}.admin-ui-actions.admin-workspace-actions{display:flex;flex-wrap:wrap;gap:8px;width:100%;max-width:100%}.admin-workspace-actions>.admin-ui-button{min-width:0;flex:0 1 auto}.admin-workspace-tabs{gap:3px 17px}.admin-workspace-tab{min-height:42px}}
.admin-ui-actions{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.admin-ui-button,.admin-ui .inventory-button{display:inline-flex;align-items:center;justify-content:center;gap:6px;min-height:36px;padding:7px 15px;border:1px solid var(--admin-border);border-radius:5px;background:var(--admin-bg);color:var(--admin-text)!important;text-decoration:none;font:inherit;font-weight:500;line-height:1.5;cursor:pointer;white-space:nowrap}
.admin-ui-button:hover,.admin-ui .inventory-button:hover{background:var(--admin-soft);border-color:var(--admin-muted)}
.admin-ui-button-primary,.admin-ui .inventory-button{background:var(--admin-ui-brand);color:#fff!important;border-color:var(--admin-ui-brand)}
.admin-ui-button-primary:hover,.admin-ui .inventory-button:hover{background:var(--admin-ui-brand-hover);border-color:var(--admin-ui-brand-hover)}
.admin-ui-button:disabled{opacity:.5;cursor:not-allowed}
.admin-ui-link,.admin-ui .inventory-link{color:var(--admin-accent);text-decoration:none;cursor:pointer}
.admin-ui-link:hover,.admin-ui .inventory-link:hover{text-decoration:underline}
.admin-ui a:focus-visible,.admin-ui button:focus-visible,.admin-ui summary:focus-visible,.admin-ui [tabindex]:focus-visible{outline:2px solid var(--admin-accent);outline-offset:3px}
.admin-ui-card{min-width:0;width:100%;background:var(--admin-bg);border:1px solid var(--admin-border);border-radius:var(--admin-ui-radius);overflow:hidden}
.admin-ui-card-heading{display:flex;align-items:center;justify-content:space-between;gap:20px;padding:var(--admin-ui-card-padding);border-bottom:1px solid var(--admin-border)}
.admin-ui-card-heading h2{font-size:15px;font-weight:600}
.admin-ui-card-body{padding:var(--admin-ui-card-padding)}
.admin-ui-filters{display:flex;align-items:flex-end;gap:14px;flex-wrap:wrap;padding:var(--admin-ui-card-padding);border-bottom:1px solid var(--admin-border)}
.admin-ui-filters label{display:grid;gap:6px;min-width:145px;flex:1}
.admin-ui-filters .admin-ui-search{flex:2;min-width:220px}
.admin-ui label{font-size:12px;font-weight:500}
.admin-ui input:not([type=hidden]):not([type=checkbox]),.admin-ui select,.admin-ui textarea{width:100%;min-height:36px;border:1px solid var(--admin-border);border-radius:5px;background:var(--admin-bg);padding:7px 10px;color:var(--admin-text);font:inherit;font-size:13px}
.admin-ui input::placeholder{color:var(--admin-muted)}
.admin-ui select{padding-right:30px}
.admin-ui input:focus,.admin-ui select:focus,.admin-ui textarea:focus{border-color:var(--admin-accent);outline:2px solid var(--admin-focus);outline-offset:0}
.admin-ui input[readonly]{background:var(--admin-soft)}
.admin-ui input[type=checkbox]{accent-color:var(--admin-ui-brand)}
.admin-ui-filter-actions{display:flex;gap:8px}
.admin-ui-table-scroll{width:100%;max-height:680px;max-height:min(70vh,680px);overflow:auto;overscroll-behavior-x:contain}
.admin-ui-table{width:100%;border-collapse:separate;border-spacing:0;font-size:13px;text-align:left}
.admin-ui-table th{position:sticky;top:0;z-index:1;background:var(--admin-soft);font-size:12px;font-weight:500;color:var(--admin-muted);white-space:nowrap}
.admin-ui-table th,.admin-ui-table td{padding:var(--admin-ui-cell-padding);border-bottom:1px solid var(--admin-border);vertical-align:middle}
.admin-ui-table th:first-child,.admin-ui-table td:first-child{padding-left:20px}
.admin-ui-table th:last-child,.admin-ui-table td:last-child{padding-right:20px}
.admin-ui-table tbody tr:last-child td{border-bottom:0}
.admin-ui-table tbody tr:hover{background:var(--admin-soft)}
.admin-ui-table .admin-ui-number,.admin-ui-table .admin-ui-row-actions{text-align:right;font-variant-numeric:tabular-nums}
.admin-ui-product-name{min-width:210px;max-width:320px;font-weight:500;overflow-wrap:anywhere}
.admin-ui-meta{display:block;font-size:11px;font-weight:400;color:var(--admin-muted);margin-top:4px;overflow-wrap:anywhere}
.admin-ui-nowrap{white-space:nowrap}
.admin-ui-row-actions{white-space:nowrap}
.admin-ui-row-actions>div{display:flex;align-items:center;justify-content:flex-end;gap:14px}
.admin-ui-row-actions button{border:0;background:transparent;color:var(--admin-accent);font:inherit;cursor:pointer;padding:4px 0}
.admin-ui-badge{display:inline-flex;align-items:center;padding:2px 8px;border-radius:4px;font-size:11px;line-height:1.6;border:1px solid var(--admin-border);background:var(--admin-soft);color:var(--admin-muted);white-space:nowrap}
.admin-ui-badge-success{color:#15803d;background:#f0fdf4;border-color:#bbf7d0}
.admin-ui-badge-warning{color:#a16207;background:#fffbeb;border-color:#fde68a}
.admin-ui-badge-danger{color:#b91c1c;background:#fef2f2;border-color:#fecaca}
.dark .admin-ui-badge-success{color:#86efac;background:#143321;border-color:#285a3b}
.dark .admin-ui-badge-warning{color:#fde68a;background:#3d3019;border-color:#6c5324}
.dark .admin-ui-badge-danger{color:#fca5a5;background:#3d2020;border-color:#693434}
.admin-ui-empty{text-align:center;padding:50px 22px!important;color:var(--admin-muted)}
.admin-ui-empty strong{display:block;font-weight:500;color:var(--admin-text);margin-bottom:5px}
.admin-ui-empty .admin-ui-button{margin-top:16px}
.admin-ui-pagination{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:16px 22px;border-top:1px solid var(--admin-border);color:var(--admin-muted);font-size:12px}
.admin-ui-pagination strong{color:var(--admin-text);font-weight:500}
.admin-ui-pagination nav{display:flex;align-items:center;gap:5px;flex-wrap:wrap}
.admin-ui-pagination nav>a,.admin-ui-pagination nav>span{display:inline-flex;justify-content:center;align-items:center;min-height:30px;min-width:30px;padding:4px 8px;border:1px solid var(--admin-border);border-radius:4px;color:var(--admin-text);text-decoration:none}
.admin-ui-pagination [aria-current=page]{color:var(--admin-accent);background:var(--admin-focus);border-color:var(--admin-accent)}
.admin-ui-pagination [aria-disabled=true]{color:var(--admin-muted);opacity:.55}
.admin-ui-dialog{margin:auto;border:1px solid var(--admin-border);padding:0;border-radius:var(--admin-ui-radius);color:var(--admin-text);background:var(--admin-bg);width:min(820px,calc(100vw - 32px));max-height:calc(100dvh - 40px);overflow:auto;box-shadow:0 20px 70px #0004}
.admin-ui-dialog::backdrop{background:#0f172a80}
.admin-ui-dialog-heading{position:sticky;top:0;z-index:2;display:flex;justify-content:space-between;align-items:flex-start;gap:18px;padding:var(--admin-ui-card-padding);border-bottom:1px solid var(--admin-border);background:var(--admin-bg)}
.admin-ui-dialog-heading h2{font-size:17px;font-weight:600}
.admin-ui-dialog-close{border:0;background:var(--admin-soft);color:var(--admin-muted);display:grid;place-items:center;width:30px;height:30px;flex-shrink:0;border-radius:5px;font-size:22px;cursor:pointer}
.admin-ui-dialog-body{padding:var(--admin-ui-card-padding)}
.admin-ui-dialog-body h3{font-size:14px;font-weight:600;margin-bottom:14px}
.admin-ui .inventory-box{border:1px solid var(--admin-border);border-radius:6px;padding:18px;margin-bottom:16px}
.admin-ui .inventory-box:last-child{margin-bottom:0}
.admin-ui .inventory-form{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));align-items:end;gap:14px;margin-top:14px}
.admin-ui .inventory-form label{min-width:0}
.admin-ui .inventory-form>button,.admin-ui .inventory-form>p,.admin-ui .inventory-form>details{grid-column:1/-1}
.admin-ui .inventory-form>button{justify-self:start;margin-top:4px}
.admin-ui .inventory-form details>label{margin-top:10px}
.admin-ui details>summary{cursor:pointer;color:var(--admin-accent);font-size:12px;list-style:revert}
.admin-ui .inventory-note{color:var(--admin-muted);font-size:12px;margin:8px 0}
.admin-ui .inventory-alert{margin:0;border-radius:6px;font-size:13px}
.admin-ui-facts{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:20px;margin:0}
.admin-ui-facts dt{font-size:12px;color:var(--admin-muted);margin-bottom:5px}
.admin-ui-facts dd{margin:0;font-size:13px;overflow-wrap:anywhere}
.admin-ui-option{padding:var(--admin-ui-card-padding);border-top:1px solid var(--admin-border)}
.admin-ui-option:first-child{border-top:0}
.admin-ui-option-heading{display:flex;align-items:center;gap:12px;justify-content:space-between}
.admin-ui-option h3{font-size:14px;font-weight:600}
.admin-ui-option-list{display:flex;gap:8px;flex-wrap:wrap;list-style:none;padding:0;margin:14px 0}
.admin-ui-option-list li{padding:5px 10px;border:1px solid var(--admin-border);border-radius:4px;background:var(--admin-soft);font-size:12px}
.admin-ui-sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
@media(max-width:640px){.admin-ui{gap:16px}.admin-ui-heading,.admin-ui-card-heading{flex-direction:column;align-items:stretch;gap:12px}.admin-ui-heading h2{font-size:17px}.admin-ui-actions{gap:8px}.admin-ui-actions>.admin-ui-button{flex:1}.admin-ui-filters{display:grid;grid-template-columns:minmax(0,1fr);padding:18px;gap:14px}.admin-ui-filters label,.admin-ui-filters .admin-ui-search{min-width:0}.admin-ui-filter-actions>*{flex:1}.admin-ui-card-heading,.admin-ui-card-body,.admin-ui-option{padding:18px}.admin-ui-pagination{padding:14px 18px;flex-direction:column;align-items:flex-start;gap:12px}.admin-ui-dialog-heading{padding:17px 18px}.admin-ui-dialog-body{padding:18px}.admin-ui .inventory-form{grid-template-columns:minmax(0,1fr)}.admin-ui-facts{grid-template-columns:minmax(0,1fr);gap:16px}.admin-ui-option-heading{align-items:flex-start}}
@media(prefers-reduced-motion:reduce){.admin-ui *{scroll-behavior:auto!important;transition:none!important}}
</style>
