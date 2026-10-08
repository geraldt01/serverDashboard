<style>
    .ticket-section { margin-top:16px; }
    .ticket-command { display:inline-flex; align-items:center; justify-content:center; padding:10px 15px; border:1px solid var(--line); border-radius:8px; background:var(--panel); color:var(--ink); text-decoration:none; font-size:13.5px; font-weight:700; }
    .ticket-command:hover { border-color:var(--purple); }
    .ticket-form > div { min-width:0; }
    .ticket-form .ticket-full { grid-column:1 / -1; }
    .ticket-form textarea { min-height:150px; resize:vertical; }
    .ticket-form input[type=file] { white-space:normal; }
    .ticket-error { color:var(--danger); border:1px solid var(--danger); padding:12px 16px; border-radius:8px; margin-bottom:16px; }
    .ticket-error ul { margin:0; padding-left:20px; }
    .ticket-table { min-width:780px; }
    .ticket-table .ticket-subject { max-width:340px; overflow-wrap:anywhere; }
    .ticket-status { white-space:nowrap; }
    .ticket-attachments { list-style:none; padding:0; margin:0; }
    .ticket-attachments li { display:flex; align-items:center; justify-content:space-between; gap:16px; padding:12px 0; border-bottom:1px solid var(--line); }
    .ticket-attachments .ticket-filename { min-width:0; overflow-wrap:anywhere; }
    .ticket-attachments .ticket-command { flex:none; }
    .ticket-meta { display:flex; gap:16px; flex-wrap:wrap; margin:12px 0 0; font-size:13px; }
    .ticket-pagination { display:flex; align-items:center; gap:12px; flex-wrap:wrap; margin-top:16px; }
    .ticket-pagination a { padding:8px; }
    @media(max-width:640px) { .ticket-attachments li { align-items:flex-start; flex-direction:column; } }
</style>