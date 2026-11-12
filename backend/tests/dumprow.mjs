import { handle } from "../supabase/functions/tax-request/handler.ts";
let row; const deps={env:()=>"k",fetch:async()=>new Response("{}"),now:()=>new Date(),db:{countRecent:async()=>0,insertRequest:async r=>{row=r;return{id:"1",reference:"R"}},updateEmailStatus:async()=>{},logEvent:async()=>{}}};
await handle(new Request("https://x",{method:"POST",body:JSON.stringify({applicant_type:"business",display_name:"Acme",contact_person:"Jo",has_tin:"yes",tin:"1000123456",email:"a@b.co",phone:"+256701287388",services:["annual_return","other"],other_service:"Zed",tax_periods:"FY26",turnover_band:"under_10m",year_end:"30_june",consent:true,elapsed_ms:9000,extra:{q:"a"}})}),deps);
console.log(JSON.stringify(row));
