(function(){
  const page=document.body.dataset.registrationPage;
  const api=window.InternPilihGoogleAuth.appPath('/api/student-registration.php');
  const $=id=>document.getElementById(id);
  async function request(action,options){
    const response=await fetch(api+'?action='+encodeURIComponent(action),{credentials:'same-origin',cache:'no-store',...options});
    const data=await response.json().catch(()=>({}));
    if(response.status===401){location.replace(window.InternPilihGoogleAuth.appPath('/login.html'));throw Error('Session expired');}
    if(!response.ok)throw Error(data.error||'Request failed.');
    return data;
  }
  async function logout(){
    await window.InternPilihGoogleAuth.signOut();
    await fetch(window.InternPilihGoogleAuth.appPath('/api/auth.php'),{method:'DELETE'}).catch(()=>{});
    sessionStorage.removeItem('user');
    location.replace(window.InternPilihGoogleAuth.appPath('/login.html'));
  }
  document.querySelectorAll('[data-registration-logout]').forEach(button=>button.addEventListener('click',logout));
  function fillList(id,values){
    $(id).replaceChildren(...[...new Set(values)].sort().map(value=>{const option=document.createElement('option');option.value=value;return option;}));
  }
  function showStatus(data){
    const r=data.request;
    if(!r){location.replace(window.InternPilihGoogleAuth.appPath('/complete-student-profile.html'));return;}
    const labels={pending:'Pending Coordinator Approval',pending_assignment:'Pending Coordinator Assignment',correction_required:'Correction Required',approved:'Approved',rejected:'Rejected'};
    $('status-heading').textContent=labels[r.status]||'Registration Status';
    $('status-copy').textContent=r.status==='pending'||r.status==='pending_assignment'
      ?'Your UiTM account has been verified. Your registration request is awaiting approval from your internship coordinator.'
      :r.status==='approved'?'Your student registration has been approved. Sign out and sign in again with UiTM Google to open your dashboard.'
      :r.status==='correction_required'?'Your coordinator requested a correction. Review the reason and resubmit your profile.'
      :'Your request was rejected. Contact your internship coordinator if you need help.';
    $('status-detail').textContent=r.status==='pending_assignment'
      ?'No matching coordinator is available for this campus, faculty, and programme. Your request remains pending assignment.'
      :'Submitted: '+new Date(r.submitted_at).toLocaleString();
    $('status-detail').hidden=false;
    $('status-reason').textContent=r.review_reason||'';
    $('status-reason').hidden=!r.review_reason;
    $('correct-link').hidden=r.status!=='correction_required';
  }
  async function initialize(){
    try{
      const data=await request('status');
      if(page==='status'){showStatus(data);return;}
      const r=data.request;
      if(r&&r.status!=='correction_required'){
        location.replace(window.InternPilihGoogleAuth.appPath('/registration-status.html'));return;
      }
      $('google-name').textContent=data.identity.name||'UiTM student';
      $('google-email').textContent=data.identity.email;
      $('email').value=data.identity.email;
      $('student-id').value=data.identity.matric_id;
      if(data.identity.picture){$('google-picture').src=data.identity.picture;$('google-picture').hidden=false;}
      const options=(await request('options')).academic_options;
      const campus=$('campus'),faculty=$('faculty'),programme=$('programme');
      function refreshFaculty(clear=true){if(clear){faculty.value='';programme.value='';}fillList('faculty-list',options.filter(o=>o.campus===campus.value).map(o=>o.faculty));refreshProgramme(false);}
      function refreshProgramme(clear=true){if(clear)programme.value='';fillList('programme-list',options.filter(o=>o.campus===campus.value&&o.faculty===faculty.value).map(o=>o.programme));}
      fillList('campus-list',options.map(o=>o.campus));
      campus.addEventListener('change',()=>refreshFaculty());campus.addEventListener('input',()=>refreshFaculty());
      faculty.addEventListener('change',()=>refreshProgramme());faculty.addEventListener('input',()=>refreshProgramme());
      if(r){
        $('correction-note').textContent='Correction requested: '+(r.review_reason||'Please check your details.');$('correction-note').hidden=false;
        $('legal-name').value=r.legal_name;$('phone').value=r.phone;campus.value=r.campus;refreshFaculty(false);
        faculty.value=r.faculty;refreshProgramme(false);programme.value=r.programme;
        $('semester').value=String(r.semester);$('cgpa').value=r.cgpa||'';
      }else $('legal-name').value=data.identity.name||'';
      $('registration-form').addEventListener('submit',async event=>{
        event.preventDefault();const button=$('submit-registration');button.disabled=true;
        $('registration-message').textContent='Submitting...';
        try{
          if(!campus.value.trim()||!faculty.value.trim()||!programme.value.trim())throw Error('Enter your campus, faculty, and programme.');
          const file=$('document').files[0];if(!file||file.size>4194304)throw Error('Choose a document no larger than 4 MB.');
          await request('submit',{method:'POST',body:new FormData(event.currentTarget)});
          location.replace(window.InternPilihGoogleAuth.appPath('/registration-status.html'));
        }catch(error){$('registration-message').textContent=error.message;button.disabled=false;}
      });
    }catch(error){if(page==='form')$('registration-message').textContent=error.message;else $('status-copy').textContent=error.message;}
  }
  $('refresh-status')?.addEventListener('click',()=>request('status').then(showStatus).catch(error=>{$('status-copy').textContent=error.message;}));
  initialize();
})();
