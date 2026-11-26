(function () {
  const api = window.appUrl('/api/academic-supervisors.php');
  const byId = id => document.getElementById(id);
  const message = byId('supervisor-message');
  let supervisors = [], students = [], editingId = null;
  const selected = new Set();
  async function request(action, body) {
    const response = await fetch(api + '?action=' + action, {method: body ? 'POST' : 'GET', headers: body ? {'Content-Type':'application/json'} : {}, credentials:'same-origin', cache:'no-store', body: body ? JSON.stringify(body) : undefined});
    const data = await response.json();
    if (!response.ok) throw Error(data.error || 'Request failed.');
    return data;
  }
  function node(tag, text, className) {
    const element = document.createElement(tag);
    element.textContent = text;
    if (className) element.className = className;
    return element;
  }
  function optionLabel(supervisor) { return `${supervisor.name} · ${supervisor.staff_id} (#${supervisor.id})`; }
  function render() {
    const query = byId('supervisor-search').value.trim().toLowerCase();
    const directory = byId('supervisor-directory');
    directory.replaceChildren();
    for (const supervisor of supervisors.filter(item => [item.name,item.staff_id,item.email].some(value => String(value).toLowerCase().includes(query)))) {
      const card = node('article','', 'supervisor-item');
      card.append(node('h4', supervisor.name),node('p',`${supervisor.staff_id} · ${supervisor.email}`),node('p',`${supervisor.student_count} assigned student(s) · ${supervisor.phone || 'No phone'}`));
      const edit = node('button','Edit','btn btn-outline btn-sm'); edit.type='button';
      edit.addEventListener('click', () => {
        editingId = Number(supervisor.id);
        const form = byId('supervisor-form');
        for (const field of ['name','staff_id','email','phone']) form.elements[field].value = supervisor[field] || '';
        form.elements.email_approved_for_contact.checked = supervisor.email_approved_for_contact === true || supervisor.email_approved_for_contact === 't';
        byId('form-title').textContent = 'Edit Academic Supervisor'; byId('cancel-edit').hidden = false;
        form.scrollIntoView({behavior:'smooth',block:'start'});
      }); card.append(edit); directory.append(card);
    }
    if (!directory.children.length) directory.append(node('p','No supervisors found.','supervisor-stat'));
    const options = byId('supervisor-options'); options.replaceChildren();
    for (const supervisor of supervisors) { const option = document.createElement('option'); option.value=optionLabel(supervisor); options.append(option); }
    byId('supervisor-counts').textContent = `${students.length} student(s) · ${students.filter(s=>!s.academic_supervisor_id).length} unassigned`;
    const search = byId('student-search').value.trim().toLowerCase(), filter = byId('student-filter').value;
    const list = byId('student-list'); list.replaceChildren();
    for (const student of students.filter(s => (filter==='all' || (filter==='assigned' ? Boolean(s.academic_supervisor_id) : !s.academic_supervisor_id)) && [s.name,s.matric_id].some(v=>String(v||'').toLowerCase().includes(search)))) {
      const label=node('label','', 'student-choice'); const checkbox=document.createElement('input'); checkbox.type='checkbox'; checkbox.value=student.user_id; checkbox.checked=selected.has(String(student.user_id));
      checkbox.addEventListener('change',()=>checkbox.checked ? selected.add(checkbox.value) : selected.delete(checkbox.value));
      const info=node('span',student.name + ' · ' + (student.matric_id || 'No Student ID'));
      info.append(node('small',' · '+(student.supervisor_name || 'Unassigned')));
      label.append(checkbox,info); list.append(label);
    }
    if (!list.children.length) list.append(node('p','No students found.','supervisor-stat'));
  }
  async function load() {
    const data=await request('list'); supervisors=data.supervisors; students=data.students;
    byId('supervisor-scope').textContent=`${data.scope.campus} → ${data.scope.faculty} → ${data.scope.programme}`;
    render();
    const history=await request('history'); const target=byId('assignment-history'); target.replaceChildren();
    for (const row of history.history) target.append(node('div',`${row.student_name}: ${row.previous_name || 'Unassigned'} → ${row.new_name || 'Unassigned'} · ${new Date(row.changed_at).toLocaleString()}${row.internship_intake ? ' · '+row.internship_intake : ''}`,'supervisor-history'));
    if (!target.children.length) target.append(node('p','No assignment changes yet.','supervisor-stat'));
  }
  byId('supervisor-form').addEventListener('submit',async event=>{
    event.preventDefault(); const form=event.currentTarget;
    const data={id:editingId,name:form.elements.name.value,staff_id:form.elements.staff_id.value,email:form.elements.email.value,phone:form.elements.phone.value,email_approved_for_contact:form.elements.email_approved_for_contact.checked};
    try { await request('save',data); editingId=null;form.reset();byId('form-title').textContent='Add Academic Supervisor';byId('cancel-edit').hidden=true;message.textContent='Supervisor saved.';await load(); }
    catch(error){message.textContent=error.message;}
  });
  byId('cancel-edit').addEventListener('click',()=>{editingId=null;byId('supervisor-form').reset();byId('form-title').textContent='Add Academic Supervisor';byId('cancel-edit').hidden=true;});
  async function assign(remove) {
    const ids=[...selected].map(Number); if (!ids.length) {message.textContent='Select at least one student.';return;}
    let supervisorId=null;
    if (!remove) {
      const match=supervisors.find(s=>optionLabel(s)===byId('assign-supervisor').value.trim());
      if (!match) {message.textContent='Choose a supervisor from the list.';return;}
      supervisorId=Number(match.id);
    }
    if (!window.confirm(`${remove?'Remove assignment for':'Assign supervisor to'} ${ids.length} student(s)?`)) return;
    try { const result=await request('assign',{student_ids:ids,supervisor_id:supervisorId,internship_intake:byId('assign-intake').value}); selected.clear();message.textContent=`${result.changed} assignment(s) updated.`;await load(); }
    catch(error){message.textContent=error.message;}
  }
  byId('assign-selected').addEventListener('click',()=>assign(false));
  byId('remove-selected').addEventListener('click',()=>assign(true));
  byId('select-visible').addEventListener('click',()=>{byId('student-list').querySelectorAll('input[type=checkbox]').forEach(input=>selected.add(input.value));render();});
  for (const id of ['supervisor-search','student-search']) byId(id).addEventListener('input',render);
  byId('student-filter').addEventListener('change',render);
  message.textContent='Loading...';
  Promise.resolve(window.authReady).then(load).then(()=>{message.textContent='';}).catch(error=>{message.textContent=error.message;});
})();
