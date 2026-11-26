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
  function searchablePicker(id,getItems,onSelect,onEdit){
    const input=$(id),menu=$(id+'-list');
    document.body.append(menu);
    let chosen=null,active=-1,visible=[];
    const close=()=>{menu.hidden=true;input.setAttribute('aria-expanded','false');input.removeAttribute('aria-activedescendant');active=-1;};
    const position=()=>{
      if(menu.hidden)return;
      const rect=input.getBoundingClientRect(),width=Math.min(rect.width,innerWidth-16);
      menu.style.left=Math.max(8,Math.min(rect.left,innerWidth-width-8))+'px';
      menu.style.width=width+'px';
      const below=innerHeight-rect.bottom,above=rect.top;
      menu.style.maxHeight=Math.max(120,Math.min(320,Math.max(below,above)-12))+'px';
      if(below>=Math.min(260,above)) {menu.style.top=(rect.bottom+4)+'px';menu.style.bottom='auto';}
      else {menu.style.top='auto';menu.style.bottom=(innerHeight-rect.top+4)+'px';}
    };
    const highlight=index=>{
      active=index;
      [...menu.querySelectorAll('[role="option"]')].forEach((option,i)=>{
        option.setAttribute('aria-selected',String(i===active));
        if(i===active){input.setAttribute('aria-activedescendant',option.id);option.scrollIntoView({block:'nearest'});}
      });
    };
    const render=()=>{
      const query=input.value.trim().toLocaleLowerCase();
      visible=getItems().filter(item=>(item.search||item.label).toLocaleLowerCase().includes(query)).slice(0,100);
      menu.replaceChildren();
      if(!visible.length){
        const empty=document.createElement('div');empty.className='registration-options-empty';empty.textContent='No matching options';menu.append(empty);
      }
      visible.forEach((item,index)=>{
        const option=document.createElement('button');
        option.type='button';option.className='registration-option';option.id=id+'-option-'+index;
        option.setAttribute('role','option');option.setAttribute('aria-selected','false');
        option.textContent=item.label;
        option.addEventListener('click',()=>pick(item));
        menu.append(option);
      });
      menu.hidden=false;input.setAttribute('aria-expanded','true');position();active=-1;
    };
    const pick=item=>{chosen=item;input.value=item.label;onSelect(item);input.focus();close();};
    const clear=()=>{chosen=null;input.value='';close();};
    input.addEventListener('input',()=>{if(chosen&&input.value!==chosen.label){chosen=null;onEdit();}render();});
    input.addEventListener('focus',render);
    input.addEventListener('click',render);
    input.addEventListener('keydown',event=>{
      if(event.key==='ArrowDown'||event.key==='ArrowUp'){
        event.preventDefault();
        if(menu.hidden)render();
        if(visible.length)highlight(active<0?(event.key==='ArrowDown'?0:visible.length-1):(active+(event.key==='ArrowDown'?1:visible.length-1))%visible.length);
      }else if(event.key==='Enter'&&!menu.hidden&&active>=0){event.preventDefault();pick(visible[active]);}
      else if(event.key==='Escape')close();
      else if(event.key==='Tab')close();
    });
    document.addEventListener('pointerdown',event=>{if(event.target!==input&&!menu.contains(event.target))close();});
    window.addEventListener('resize',position);
    window.addEventListener('scroll',position,true);
    return {clear,select:item=>{chosen=item;input.value=item.label;onSelect(item);close();},value:()=>chosen?.value||null,close};
  }
  function showStatus(data){
    const r=data.request;
    if(!r){location.replace(window.InternPilihGoogleAuth.appPath('/complete-student-profile.html'));return;}
    $('status-heading').textContent='Complete your student profile';
    $('status-copy').textContent='Your UiTM Google account is verified. Review your details to finish registering.';
    $('status-detail').hidden=true;
    $('status-reason').hidden=true;
    $('correct-link').textContent='Continue registration';
    $('correct-link').hidden=false;
    $('refresh-status').hidden=true;
  }
  async function initialize(){
    try{
      const data=await request('status');
      if(page==='status'){showStatus(data);return;}
      const r=data.request;
      if(r&&r.status==='registered'){
        location.replace(window.InternPilihGoogleAuth.appPath('/login.html'));return;
      }
      $('google-name').textContent=data.identity.name||'UiTM student';
      $('google-email').textContent=data.identity.email;
      $('email').value=data.identity.email;
      $('student-id').value=data.identity.matric_id;
      if(data.identity.picture){$('google-picture').src=data.identity.picture;$('google-picture').hidden=false;}
      const catalogue=(await request('options')).academic_catalogue;
      const programmes=catalogue.programmes;
      let studyLevel='',campusName='',facultyName='';
      const matchingLevel=programme=>programme.level===studyLevel;
      const verifiedAtCampus=programme=>programme.campuses.includes(campusName);
      const clearProgramme=()=>{$('programme-name').value='';$('programme-code').value='';$('academic-note').textContent='';programmePicker.clear();$('programme').disabled=true;};
      const clearFaculty=()=>{facultyName='';facultyPicker.clear();$('faculty').disabled=true;clearProgramme();};
      const clearCampus=()=>{campusName='';campusPicker.clear();$('campus').disabled=true;clearFaculty();};
      const campusPicker=searchablePicker('campus',
        ()=>catalogue.campuses.map(entry=>({value:entry.name||entry,label:entry.name||entry,search:[entry.name||entry,...(entry.aliases||[])].join(' ')})),
        item=>{if(campusName!==item.value){campusName=item.value;clearFaculty();$('faculty').disabled=false;$('faculty').placeholder='Search faculty or college';}},
        ()=>{campusName='';clearFaculty();});
      const facultyPicker=searchablePicker('faculty',
        ()=>[...new Set(programmes.filter(matchingLevel).map(p=>p.faculty))]
          .sort((a,b)=>Number(programmes.some(p=>matchingLevel(p)&&p.faculty===b&&verifiedAtCampus(p)))-Number(programmes.some(p=>matchingLevel(p)&&p.faculty===a&&verifiedAtCampus(p)))||a.localeCompare(b))
          .map(name=>({value:name,label:name,search:name+' '+(catalogue.faculty_aliases?.[name]||[]).join(' ')})),
        item=>{if(facultyName!==item.value){facultyName=item.value;clearProgramme();$('programme').disabled=false;$('programme').placeholder='Search code or programme name';}},
        ()=>{facultyName='';clearProgramme();});
      const programmePicker=searchablePicker('programme',
        ()=>programmes.filter(p=>matchingLevel(p)&&p.faculty===facultyName).sort((a,b)=>Number(verifiedAtCampus(b))-Number(verifiedAtCampus(a))||a.code.localeCompare(b.code)).map(p=>({
          value:p,label:p.code+' — '+(p.display_name||p.name)+(verifiedAtCampus(p)?'':' (campus offering unverified)'),
          search:[p.code,p.name,p.display_name,...(p.aliases||[])].join(' ')
        })),
        item=>{
          $('programme-name').value=item.value.name;
          $('programme-code').value=item.value.code;
          $('academic-note').textContent=!verifiedAtCampus(item.value)
            ? 'Campus offering unverified. Confirm that this programme is offered at your selected campus; your academic details are self-reported.'
            : '';
        },
        ()=>{$('programme-name').value='';$('programme-code').value='';$('academic-note').textContent='';});
      document.querySelectorAll('[name="study_level"]').forEach(radio=>radio.addEventListener('change',()=>{
        if(!radio.checked)return;
        studyLevel=radio.value;clearCampus();$('campus').disabled=false;$('campus').placeholder='Search campus';
      }));
      if(r){
        $('legal-name').value=r.legal_name;$('phone').value=r.phone;
        const savedLevel=r.study_level||programmes.find(p=>p.code===r.programme_code)?.level||'';
        if(savedLevel){const radio=document.querySelector(`[name="study_level"][value="${savedLevel}"]`);if(radio){radio.checked=true;radio.dispatchEvent(new Event('change'));}}
        if(catalogue.campuses.some(entry=>(entry.name||entry)===r.campus)){
          campusPicker.select({value:r.campus,label:r.campus});
          if(programmes.some(p=>p.faculty===r.faculty&&matchingLevel(p))){
            facultyPicker.select({value:r.faculty,label:r.faculty});
            const saved=programmes.find(p=>p.code===r.programme_code&&matchingLevel(p))
              ||programmes.find(p=>p.name===r.programme&&p.faculty===r.faculty&&matchingLevel(p));
            if(saved)programmePicker.select({value:saved,label:saved.code+' — '+(saved.display_name||saved.name)});
          }
        }
        $('semester').value=String(r.semester);$('cgpa').value=r.cgpa||'';
      }else $('legal-name').value=data.identity.name||'';
      $('registration-form').addEventListener('submit',async event=>{
        event.preventDefault();const button=$('submit-registration');button.disabled=true;
        $('registration-message').textContent='Submitting...';
        try{
          if(!studyLevel||!campusPicker.value()||!facultyPicker.value()||!programmePicker.value())throw Error('Choose a study level, campus, faculty or college, and programme from the list.');
          const result=await request('submit',{method:'POST',body:new FormData(event.currentTarget)});
          sessionStorage.setItem('user',JSON.stringify(result.user));
          location.replace(window.InternPilihGoogleAuth.appPath(result.redirect||'/student/dashboard.html'));
        }catch(error){$('registration-message').textContent=error.message;button.disabled=false;}
      });
    }catch(error){if(page==='form')$('registration-message').textContent=error.message;else $('status-copy').textContent=error.message;}
  }
  $('refresh-status')?.addEventListener('click',()=>request('status').then(showStatus).catch(error=>{$('status-copy').textContent=error.message;}));
  initialize();
})();
