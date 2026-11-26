(function(){
  const type=document.body.dataset.studentManagement;
  const list=document.getElementById('management-list');
  const message=document.getElementById('management-message');
  const api=window.appUrl('/api/student-registration.php');
  function detail(label,value){const item=document.createElement('div');const heading=document.createElement('span');heading.textContent=label;item.append(heading,document.createTextNode(value==null||value===''?'—':String(value)));return item;}
  async function get(action){const response=await fetch(api+'?action='+action,{credentials:'same-origin',cache:'no-store'});const data=await response.json();if(!response.ok)throw Error(data.error||'Could not load students.');return data;}
  async function review(id,decision){
    let reason='';
    if(decision!=='approve'){
      reason=window.prompt(decision==='reject'?'Reason for rejection:':'Correction needed:')||'';
      if(!reason.trim())return;
    }else if(!window.confirm('Approve this student registration?'))return;
    message.textContent='Saving review...';
    try{
      const response=await fetch(api+'?action=review',{method:'POST',headers:{'Content-Type':'application/json'},credentials:'same-origin',body:JSON.stringify({id,decision,reason})});
      const data=await response.json();if(!response.ok)throw Error(data.error||'Could not save review.');
      message.textContent='Review saved.';await load();
    }catch(error){message.textContent=error.message;}
  }
  async function claim(id){
    message.textContent='Assigning request...';
    try{
      const response=await fetch(api+'?action=claim',{method:'POST',headers:{'Content-Type':'application/json'},credentials:'same-origin',body:JSON.stringify({id})});
      const data=await response.json();if(!response.ok)throw Error(data.error||'Could not assign request.');
      message.textContent='Request assigned to you.';await load();
    }catch(error){message.textContent=error.message;}
  }
  function card(row){
    const panel=document.createElement('article');panel.className='panel management-card';
    const heading=document.createElement('h3');heading.textContent=row.legal_name||row.name;panel.append(heading);
    const details=document.createElement('div');details.className='management-details';
    for(const [label,value] of [['Student ID',row.matric_id],['Email',row.email],['Campus',row.campus],['Faculty',row.faculty],['Programme',row.programme],['Semester',row.semester],['CGPA',row.cgpa],['Phone',row.phone],['Status',row.status],['Submitted',row.submitted_at?new Date(row.submitted_at).toLocaleString():null]]){
      if(value!==undefined)details.append(detail(label,value));
    }
    panel.append(details);
    if(row.review_reason){const reason=document.createElement('p');reason.className='management-reason';reason.textContent='Reason: '+row.review_reason;panel.append(reason);}
    if(type==='requests'){
      const actions=document.createElement('div');actions.className='management-actions';
      if(row.document_name){
        const file=document.createElement('a');file.className='btn btn-outline btn-sm';file.href=api+'?action=document&id='+encodeURIComponent(row.id);file.textContent='Supporting document: '+row.document_name;actions.append(file);
      }
      if(row.status==='pending_assignment'||(row.status==='registered'&&!row.coordinator_id)){
        const button=document.createElement('button');button.type='button';button.className='btn btn-sm btn-outline';button.textContent='Assign to me';button.addEventListener('click',()=>claim(row.id));actions.append(button);
      }
      if(row.status==='pending')for(const [label,decision,css] of [['Approve','approve','btn-success'],['Reject','reject','btn-danger'],['Request Correction','correction_required','btn-outline']]){
        const button=document.createElement('button');button.type='button';button.className='btn btn-sm '+css;button.textContent=label;button.addEventListener('click',()=>review(row.id,decision));actions.append(button);
      }
      panel.append(actions);
    }else if(row.status==='registered'&&!row.coordinator_id){
      const note=document.createElement('p');note.textContent='Programme Coordinator assignment pending.';panel.append(note);
      const actions=document.createElement('div');actions.className='management-actions';
      const button=document.createElement('button');button.type='button';button.className='btn btn-sm btn-outline';
      button.textContent='Assign to me';button.addEventListener('click',()=>claim(row.id));
      actions.append(button);panel.append(actions);
    }
    return panel;
  }
  async function load(){try{
    await window.authReady;
    const data=await get(type==='requests'?'requests':'students');
    const rows=data.requests||data.students||[];
    if(type==='students'){
      const pending=(await get('requests')).requests.filter(row=>row.status==='registered'&&!row.coordinator_id);
      rows.push(...pending);
    }
    list.replaceChildren(...rows.map(card));
    if(!rows.length)message.textContent=type==='requests'?'No registration requests are assigned to you.':'No registered students are assigned to you.';
    else if(message.textContent==='Loading...')message.textContent='';
  }catch(error){message.textContent=error.message;}}
  message.textContent='Loading...';load();
})();
