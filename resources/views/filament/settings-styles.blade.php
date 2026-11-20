<style>
.settings-center{--settings-primary:var(--admin-ui-accent);--settings-surface:var(--admin-ui-surface);--settings-subtle:var(--admin-ui-subtle);--settings-text:var(--admin-ui-text);--settings-muted:var(--admin-ui-muted);--settings-border:var(--admin-ui-border);--settings-active:var(--admin-ui-active);--settings-input:var(--admin-ui-surface);color:var(--settings-text);font-size:var(--admin-ui-font-size);line-height:1.65}
.dark .settings-center{--settings-input:var(--admin-ui-subtle)}
.settings-card{width:100%;min-width:0;background:var(--settings-surface);border:1px solid var(--settings-border);border-radius:var(--admin-ui-radius);box-shadow:0 1px 3px #00000004}
.settings-card-header{display:flex;align-items:flex-start;justify-content:space-between;gap:20px;padding:var(--admin-ui-card-padding);border-bottom:1px solid var(--settings-border)}
.settings-heading-row{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.settings-card-header h2{margin:0;font-size:15px;line-height:1.5;font-weight:600;color:var(--settings-text)}
.settings-card-header p{margin:6px 0 0;color:var(--settings-muted);font-size:13px;max-width:680px}
.settings-badge{display:inline-flex;align-items:center;padding:1px 7px;border:1px solid var(--settings-border);border-radius:4px;background:var(--settings-subtle);font-size:11px;line-height:1.6;font-weight:400;color:var(--settings-muted);white-space:nowrap}
.settings-text-link{flex-shrink:0;color:var(--settings-primary);font-size:13px;text-decoration:none;white-space:nowrap;padding-top:4px}
.settings-text-link:hover{text-decoration:underline}
.settings-card-body{padding:var(--admin-ui-card-padding)}
.settings-field-group+.settings-field-group{border-top:1px solid var(--settings-border);padding-top:25px;margin-top:26px}
.settings-field-group h3{margin:0 0 22px;font-size:14px;font-weight:600;color:var(--settings-text)}
.settings-fields{display:grid;gap:20px;margin:0}
.settings-field{display:grid;grid-template-columns:148px minmax(0,1fr);gap:16px;align-items:start;min-width:0}
.settings-field dt{padding-top:8px;font-size:13px;font-weight:500;color:var(--settings-text);overflow-wrap:anywhere}
.settings-field dt label{cursor:pointer}
.settings-field-badge{display:block;margin-top:3px;color:var(--settings-muted);font-size:11px;font-weight:400}
.settings-control{margin:0;min-width:0;max-width:620px}
.settings-control input:not([type=checkbox]):not([type=hidden]),.settings-control select,.settings-control textarea,.settings-optional-note input{display:block;box-sizing:border-box;width:100%;min-width:0;min-height:38px;border:1px solid var(--settings-border);border-radius:5px;padding:7px 11px;background:var(--settings-input);color:var(--settings-text);font:inherit;font-size:13px;line-height:1.6;box-shadow:0 1px 2px #00000003;transition:border-color .15s,box-shadow .15s}
.settings-control select{padding-right:32px}
.settings-control textarea{resize:vertical;min-height:94px}
.settings-control input::placeholder,.settings-control textarea::placeholder{color:var(--settings-muted);opacity:.75}
.settings-control input[readonly]{background:var(--settings-subtle);cursor:text}
.settings-control .settings-code-input{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12px;min-height:150px}
.settings-control input:focus,.settings-control select:focus,.settings-control textarea:focus,.settings-optional-note input:focus{border-color:var(--settings-primary);outline:2px solid color-mix(in srgb,var(--settings-primary) 16%,transparent);outline-offset:0;box-shadow:none}
.settings-center a:focus-visible,.settings-center button:focus-visible,.settings-center summary:focus-visible{outline:2px solid var(--settings-primary);outline-offset:3px}
.settings-field-help{font-size:12px;line-height:1.7;color:var(--settings-muted);margin:6px 0 0;overflow-wrap:anywhere}
.settings-read-value{display:block;padding:8px 0;font-size:13px;overflow-wrap:anywhere;min-height:38px}
.settings-read-multiline{white-space:pre-wrap}
.settings-switch{display:inline-flex;position:relative;align-items:center;gap:10px;min-height:38px;font-size:13px;font-weight:400;cursor:pointer;user-select:none}
.settings-switch input[type=checkbox]{position:absolute;left:0;top:7px;width:40px;height:24px;margin:0;opacity:0;cursor:pointer}
.settings-switch-track{display:inline-block;position:relative;flex-shrink:0;width:38px;height:22px;border-radius:20px;background:#cbd0da;transition:background .15s;pointer-events:none}
.dark .settings-switch-track{background:#52525b}
.settings-switch-track:after{content:"";position:absolute;width:16px;height:16px;left:3px;top:3px;border-radius:50%;background:white;box-shadow:0 1px 2px #0002;transition:transform .15s}
.settings-switch input:checked+.settings-switch-track{background:var(--admin-ui-brand)}
.settings-switch input:checked+.settings-switch-track:after{transform:translateX(16px)}
.settings-switch input:focus-visible+.settings-switch-track{outline:2px solid var(--settings-primary);outline-offset:3px}
.settings-switch-on{display:none;color:var(--settings-text)}
.settings-switch-off{color:var(--settings-muted)}
.settings-switch input:checked~.settings-switch-on{display:inline}
.settings-switch input:checked~.settings-switch-off{display:none}
.settings-inline-note{padding:11px 14px;border-radius:5px;background:var(--settings-subtle);border-left:3px solid var(--settings-border);font-size:12px;color:var(--settings-muted);margin:20px 0 0;line-height:1.8}
.settings-optional-note{margin-top:28px;padding-top:22px;border-top:1px solid var(--settings-border);font-size:12px;color:var(--settings-muted)}
.settings-optional-note summary{cursor:pointer;width:fit-content}
.settings-optional-note label{display:block;max-width:620px;margin-top:12px}
.settings-optional-note input{margin-top:6px}
.settings-savebar{position:sticky;bottom:0;z-index:5;display:flex;align-items:center;justify-content:space-between;gap:14px;min-height:70px;padding:14px 20px;border-top:1px solid var(--settings-border);border-radius:0 0 var(--admin-ui-radius) var(--admin-ui-radius);background:var(--settings-surface);box-shadow:0 -3px 10px #00000003}
.settings-savebar>span{font-size:12px;color:var(--settings-muted)}
.settings-savebar-actions{display:flex;align-items:center;gap:10px}
.settings-button{display:inline-flex;align-items:center;justify-content:center;gap:7px;min-height:36px;padding:7px 17px;border:1px solid transparent;border-radius:5px;font-family:inherit;font-size:13px;font-weight:500;line-height:1.5;text-decoration:none;cursor:pointer;white-space:nowrap;transition:background .15s,border-color .15s}
.settings-button-primary{background:var(--admin-ui-brand);color:white!important;border-color:var(--admin-ui-brand)}
.settings-button-primary:hover{background:var(--admin-ui-brand-hover);border-color:var(--admin-ui-brand-hover)}
.settings-button-secondary{background:var(--settings-surface);border-color:var(--settings-border);color:var(--settings-text)}
.settings-button-secondary:hover{background:var(--settings-subtle);border-color:var(--settings-muted)}
.settings-test{display:flex;align-items:center;justify-content:space-between;gap:20px;padding:var(--admin-ui-card-padding);border-top:1px solid var(--settings-border);border-radius:0 0 var(--admin-ui-radius) var(--admin-ui-radius);background:var(--settings-subtle)}
.settings-test h3{display:flex;align-items:center;gap:8px;margin:0;font-size:13px;font-weight:600}
.settings-test p{margin:5px 0 0;font-size:12px;color:var(--settings-muted)}
.settings-test form{flex-shrink:0}
.settings-connection{display:flex;gap:16px;align-items:center;padding:8px 0 12px}
.settings-connection-icon{display:grid;place-items:center;width:46px;height:46px;flex-shrink:0;font-size:26px;color:var(--settings-primary);background:var(--settings-active);border-radius:9px}
.settings-connection h3{font-size:15px;font-weight:600;margin:0}
.settings-connection p{font-size:13px;color:var(--settings-muted);margin:6px 0 0}
.settings-connection~.settings-button{margin-top:24px}
.settings-center>.inventory-alert{margin-bottom:20px;border-radius:6px;font-size:13px}
.settings-plugin-summary{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:var(--admin-ui-card-padding);border-bottom:1px solid var(--settings-border);font-size:13px;color:var(--settings-muted)}
.settings-plugin-summary strong{font-weight:600;color:var(--settings-text)}
.settings-summary-divider{margin:0 10px;color:var(--settings-border)}
.settings-plugin-scroll{overflow-x:auto;border-radius:0 0 var(--admin-ui-radius) var(--admin-ui-radius)}
.settings-plugin-table{width:100%;border-collapse:collapse;text-align:left;font-size:13px;white-space:nowrap}
.settings-plugin-table th{font-weight:500;font-size:12px;color:var(--settings-muted);background:var(--settings-subtle)}
.settings-plugin-table th,.settings-plugin-table td{padding:var(--admin-ui-cell-padding);border-bottom:1px solid var(--settings-border);vertical-align:middle}
.settings-plugin-table th:first-child,.settings-plugin-table td:first-child{padding-left:20px}
.settings-plugin-table th:last-child,.settings-plugin-table td:last-child{padding-right:20px}
.settings-plugin-table tbody tr:last-child td{border-bottom:0}
.settings-plugin-table tbody tr:hover{background:var(--settings-subtle)}
.settings-plugin-table code{font-size:12px;color:var(--settings-muted)}
.settings-plugin-table .settings-plugin-description{min-width:180px;max-width:300px;white-space:normal;color:var(--settings-muted);line-height:1.7}
.settings-plugin-id{color:var(--settings-muted)}
.settings-plugin-name{font-weight:600}
.settings-plugin-state{display:inline-flex;align-items:center;gap:7px;color:var(--settings-muted)}
.settings-plugin-state>span{width:6px;height:6px;border-radius:50%;background:#94a3b8}
.settings-plugin-state.is-enabled{color:#15803d}
.settings-plugin-state.is-enabled>span{background:#22c55e}
.dark .settings-plugin-state.is-enabled{color:#86efac}
.settings-plugin-actions{display:flex;align-items:center;gap:16px;min-height:32px}
.settings-plugin-actions .settings-text-link{padding:0}
.settings-plugin-toggle{padding:4px 0;background:transparent;border:0;color:var(--settings-primary);font-size:13px;cursor:pointer}
.settings-plugin-toggle.is-disable{color:#dc2626}
.dark .settings-plugin-toggle.is-disable{color:#fca5a5}
.settings-plugin-toggle:disabled{color:var(--settings-muted);opacity:.55;cursor:not-allowed}
.settings-plugin-incomplete{display:block;font-size:11px;color:var(--settings-muted);margin-top:3px}
.settings-plugin-fallback{font-size:12px;color:var(--settings-muted)}
.settings-plugin-footer{border-top:1px solid var(--settings-border);padding:var(--admin-ui-card-padding);font-size:12px;color:var(--settings-muted);background:var(--settings-subtle);border-radius:0 0 var(--admin-ui-radius) var(--admin-ui-radius);line-height:1.8}
.settings-plugin-config-note{margin:0 0 24px;color:var(--settings-muted);font-size:12px}
@media(max-width:640px){.settings-card-header{padding:var(--admin-ui-card-padding);gap:12px}.settings-card-header h2{font-size:15px}.settings-card-body{padding:var(--admin-ui-card-padding)}.settings-field{grid-template-columns:minmax(0,1fr);gap:7px}.settings-field dt{padding-top:0}.settings-field-badge{display:inline;margin-left:8px}.settings-fields{gap:19px}.settings-field-group h3{margin-bottom:18px}.settings-control{max-width:none}.settings-savebar{padding:12px 18px;gap:8px}.settings-savebar>span{display:none}.settings-savebar-actions{width:100%}.settings-savebar-actions button{flex:1}.settings-test{align-items:flex-start;flex-direction:column;padding:18px}.settings-test form,.settings-test button{width:100%}.settings-card-header .settings-text-link{font-size:12px}.settings-connection{align-items:flex-start}}
@media(prefers-reduced-motion:reduce){.settings-center *{transition:none!important}}
</style>
