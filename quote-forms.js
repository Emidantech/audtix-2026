/* Quotation form definitions, used by quote.html and checked by api/quote.php.
   Edit the questions here. Everything after "window.QUOTE_FORMS" must stay valid JSON, ending with a semicolon. */
window.QUOTE_FORMS = {
 "version": 1,
 "note": "One definition drives both quote.html (what the visitor sees) and api/quote.php (what the server accepts). Field types: text, email, tel, number, date, select, radio, checkboxes, textarea, items. 'show' rules: name=value (select/radio equals) or name~value (checkbox ticked).",
 "contact": {
  "title": "Your details",
  "fields": [
   {
    "name": "client_type",
    "label": "Who is asking?",
    "type": "radio",
    "required": true,
    "options": [
     [
      "business",
      "Company or business"
     ],
     [
      "organisation",
      "NGO, school or public body"
     ],
     [
      "individual",
      "Individual"
     ]
    ]
   },
   {
    "name": "full_name",
    "label": "Your full name",
    "type": "text",
    "required": true,
    "max": 160,
    "autocomplete": "name"
   },
   {
    "name": "company",
    "label": "Company or organisation name",
    "type": "text",
    "max": 190,
    "autocomplete": "organization",
    "show": "client_type!=individual",
    "required": true
   },
   {
    "name": "job_title",
    "label": "Your role",
    "type": "text",
    "max": 120,
    "show": "client_type!=individual",
    "placeholder": "e.g. Finance manager"
   },
   {
    "name": "email",
    "label": "Email",
    "type": "email",
    "required": true,
    "max": 190,
    "autocomplete": "email",
    "half": true
   },
   {
    "name": "phone",
    "label": "Phone or WhatsApp",
    "type": "tel",
    "required": true,
    "max": 40,
    "autocomplete": "tel",
    "placeholder": "+256 ...",
    "half": true
   },
   {
    "name": "location",
    "label": "City or town",
    "type": "text",
    "max": 120,
    "half": true
   },
   {
    "name": "preferred_contact",
    "label": "How should we reply?",
    "type": "select",
    "half": true,
    "options": [
     [
      "email",
      "Email"
     ],
     [
      "whatsapp",
      "WhatsApp"
     ],
     [
      "phone",
      "Phone call"
     ]
    ]
   }
  ]
 },
 "closing": {
  "title": "Budget and timing",
  "fields": [
   {
    "name": "budget",
    "label": "Budget range",
    "type": "select",
    "half": true,
    "options": [
     [
      "under_5m",
      "Under UGX 5 million"
     ],
     [
      "5m_20m",
      "UGX 5 to 20 million"
     ],
     [
      "20m_50m",
      "UGX 20 to 50 million"
     ],
     [
      "50m_150m",
      "UGX 50 to 150 million"
     ],
     [
      "over_150m",
      "Over UGX 150 million"
     ],
     [
      "not_sure",
      "Not sure yet"
     ]
    ]
   },
   {
    "name": "start_when",
    "label": "When do you want to start?",
    "type": "select",
    "half": true,
    "options": [
     [
      "asap",
      "As soon as possible"
     ],
     [
      "1_month",
      "Within a month"
     ],
     [
      "1_3_months",
      "In 1 to 3 months"
     ],
     [
      "later",
      "Later than 3 months"
     ],
     [
      "exploring",
      "Just exploring prices"
     ]
    ]
   },
   {
    "name": "needed_by",
    "label": "Date you need it done by (optional)",
    "type": "date",
    "half": true,
    "future": true
   },
   {
    "name": "heard_from",
    "label": "How did you hear about us?",
    "type": "select",
    "half": true,
    "options": [
     [
      "referral",
      "Referral"
     ],
     [
      "search",
      "Online search"
     ],
     [
      "social",
      "Social media"
     ],
     [
      "event",
      "Event or training"
     ],
     [
      "existing",
      "Existing client"
     ],
     [
      "other",
      "Other"
     ]
    ]
   },
   {
    "name": "details",
    "label": "Anything else we should know?",
    "type": "textarea",
    "max": 3000,
    "rows": 4,
    "placeholder": "The more you tell us, the more accurate the quotation."
   }
  ]
 },
 "services": {
  "erp": {
   "tag": "ERP",
   "title": "ERP deployment",
   "eyebrow": "ERP deployment",
   "short": "Pick, install and support a system",
   "intro": "Tell us about your business and how you work today. We will recommend the right ERP and send a quotation for licences, setup, data migration and training.",
   "sections": [
    {
     "title": "Your business",
     "fields": [
      {
       "name": "industry",
       "label": "Industry",
       "type": "select",
       "required": true,
       "half": true,
       "options": [
        [
         "retail",
         "Retail"
        ],
        [
         "wholesale",
         "Wholesale and distribution"
        ],
        [
         "manufacturing",
         "Manufacturing"
        ],
        [
         "construction",
         "Construction"
        ],
        [
         "services",
         "Professional services"
        ],
        [
         "hospitality",
         "Hospitality"
        ],
        [
         "health",
         "Health"
        ],
        [
         "education",
         "Education"
        ],
        [
         "ngo",
         "NGO"
        ],
        [
         "agriculture",
         "Agriculture"
        ],
        [
         "other",
         "Other"
        ]
       ]
      },
      {
       "name": "users",
       "label": "How many people will use the system?",
       "type": "number",
       "required": true,
       "min": 1,
       "max": 100000,
       "half": true
      },
      {
       "name": "branches",
       "label": "Number of branches or sites",
       "type": "number",
       "min": 1,
       "max": 10000,
       "half": true
      },
      {
       "name": "current_system",
       "label": "What do you use today?",
       "type": "select",
       "half": true,
       "options": [
        [
         "none",
         "Nothing, paper or Excel"
        ],
        [
         "quickbooks",
         "QuickBooks"
        ],
        [
         "sage",
         "Sage"
        ],
        [
         "tally",
         "Tally"
        ],
        [
         "other_erp",
         "Another ERP"
        ],
        [
         "custom",
         "A custom system"
        ]
       ]
      }
     ]
    },
    {
     "title": "What you need",
     "fields": [
      {
       "name": "platform",
       "label": "Preferred platform",
       "type": "radio",
       "required": true,
       "options": [
        [
         "odoo",
         "Odoo"
        ],
        [
         "dynamics",
         "Microsoft Dynamics 365"
        ],
        [
         "erpnext",
         "ERPNext"
        ],
        [
         "otibee",
         "Otibee ERP"
        ],
        [
         "advise",
         "Not sure, advise me"
        ]
       ]
      },
      {
       "name": "modules",
       "label": "Modules needed (choose all that apply)",
       "type": "checkboxes",
       "required": true,
       "options": [
        [
         "accounting",
         "Accounting and finance"
        ],
        [
         "inventory",
         "Inventory and warehouse"
        ],
        [
         "sales",
         "Sales and invoicing"
        ],
        [
         "purchase",
         "Purchasing"
        ],
        [
         "pos",
         "Point of sale"
        ],
        [
         "hr_payroll",
         "HR and payroll"
        ],
        [
         "manufacturing",
         "Manufacturing"
        ],
        [
         "crm",
         "CRM"
        ],
        [
         "projects",
         "Projects"
        ],
        [
         "assets",
         "Fixed assets"
        ],
        [
         "other",
         "Other"
        ]
       ]
      },
      {
       "name": "modules_other",
       "label": "Which other modules?",
       "type": "text",
       "max": 200,
       "show": "modules~other",
       "required": true
      },
      {
       "name": "hosting",
       "label": "Where should it run?",
       "type": "radio",
       "options": [
        [
         "cloud",
         "Cloud"
        ],
        [
         "on_premise",
         "Our own server"
        ],
        [
         "advise",
         "Advise me"
        ]
       ]
      },
      {
       "name": "extras",
       "label": "Also include (choose all that apply)",
       "type": "checkboxes",
       "options": [
        [
         "migration",
         "Data migration from the old system"
        ],
        [
         "efris",
         "URA EFRIS integration"
        ],
        [
         "training",
         "Staff training"
        ],
        [
         "support",
         "Ongoing support contract"
        ],
        [
         "hardware",
         "Computers or POS hardware"
        ]
       ]
      }
     ]
    }
   ]
  },
  "custom": {
   "tag": "FIN",
   "title": "Custom fintech systems",
   "eyebrow": "Custom fintech systems",
   "short": "Invoicing, collections, reporting",
   "intro": "Describe the system you have in mind. We will scope it and send a quotation with a delivery plan.",
   "sections": [
    {
     "title": "The system",
     "fields": [
      {
       "name": "system_types",
       "label": "What should it do? (choose all that apply)",
       "type": "checkboxes",
       "required": true,
       "options": [
        [
         "invoicing",
         "Invoicing and billing"
        ],
        [
         "collections",
         "Collections and reminders"
        ],
        [
         "payments",
         "Payments and mobile money"
        ],
        [
         "loans",
         "Loan management"
        ],
        [
         "sacco",
         "SACCO or savings group"
        ],
        [
         "reporting",
         "Financial reporting and dashboards"
        ],
        [
         "payroll",
         "Payroll"
        ],
        [
         "other",
         "Something else"
        ]
       ]
      },
      {
       "name": "system_other",
       "label": "Describe the other function",
       "type": "text",
       "max": 200,
       "show": "system_types~other",
       "required": true
      },
      {
       "name": "platforms",
       "label": "Platforms",
       "type": "checkboxes",
       "options": [
        [
         "web",
         "Web browser"
        ],
        [
         "android",
         "Android app"
        ],
        [
         "ios",
         "iPhone app"
        ],
        [
         "ussd",
         "USSD or SMS"
        ]
       ]
      },
      {
       "name": "integrations",
       "label": "Must connect to (choose all that apply)",
       "type": "checkboxes",
       "options": [
        [
         "mtn",
         "MTN Mobile Money"
        ],
        [
         "airtel",
         "Airtel Money"
        ],
        [
         "bank",
         "Bank API"
        ],
        [
         "ura",
         "URA EFRIS"
        ],
        [
         "erp",
         "Our ERP or accounting system"
        ],
        [
         "sms",
         "SMS gateway"
        ]
       ]
      },
      {
       "name": "users",
       "label": "Expected number of users",
       "type": "number",
       "min": 1,
       "max": 10000000,
       "half": true
      },
      {
       "name": "stage",
       "label": "Where are you now?",
       "type": "select",
       "half": true,
       "required": true,
       "options": [
        [
         "idea",
         "Just an idea"
        ],
        [
         "requirements",
         "We have written requirements"
        ],
        [
         "design",
         "We have designs"
        ],
        [
         "existing",
         "Improving an existing system"
        ]
       ]
      },
      {
       "name": "scope",
       "label": "Describe the main features",
       "type": "textarea",
       "max": 3000,
       "rows": 5,
       "required": true,
       "placeholder": "Who uses it, what they do in it, and the reports you need."
      }
     ]
    }
   ]
  },
  "ura": {
   "tag": "URA",
   "title": "URA system integration",
   "eyebrow": "URA system integration",
   "short": "Connect your system to URA",
   "intro": "Tell us which system you want connected to URA and how many invoices you issue. We will quote for the integration, testing and go-live.",
   "sections": [
    {
     "title": "Integration",
     "fields": [
      {
       "name": "integration",
       "label": "What do you need?",
       "type": "checkboxes",
       "required": true,
       "options": [
        [
         "efris_invoice",
         "EFRIS e-invoicing"
        ],
        [
         "efris_receipt",
         "EFRIS e-receipts"
        ],
        [
         "efd",
         "EFD device setup"
        ],
        [
         "stock",
         "EFRIS stock management"
        ],
        [
         "other",
         "Other"
        ]
       ]
      },
      {
       "name": "integration_other",
       "label": "Describe what else you need",
       "type": "text",
       "max": 200,
       "show": "integration~other",
       "required": true
      },
      {
       "name": "source_system",
       "label": "System to connect",
       "type": "select",
       "required": true,
       "half": true,
       "options": [
        [
         "odoo",
         "Odoo"
        ],
        [
         "dynamics",
         "Microsoft Dynamics"
        ],
        [
         "erpnext",
         "ERPNext"
        ],
        [
         "quickbooks",
         "QuickBooks"
        ],
        [
         "sage",
         "Sage"
        ],
        [
         "pos",
         "A POS system"
        ],
        [
         "custom",
         "A custom system"
        ],
        [
         "none",
         "No system yet"
        ]
       ]
      },
      {
       "name": "source_other",
       "label": "Name of the system",
       "type": "text",
       "max": 120,
       "half": true,
       "show": "source_system=custom"
      },
      {
       "name": "efris_registered",
       "label": "Is your TIN already registered for EFRIS?",
       "type": "radio",
       "options": [
        [
         "yes",
         "Yes"
        ],
        [
         "no",
         "No"
        ],
        [
         "unsure",
         "Not sure"
        ]
       ]
      },
      {
       "name": "branches",
       "label": "Branches issuing invoices",
       "type": "number",
       "min": 1,
       "max": 10000,
       "half": true
      },
      {
       "name": "invoices_month",
       "label": "Invoices per month (approx.)",
       "type": "select",
       "half": true,
       "options": [
        [
         "lt_500",
         "Under 500"
        ],
        [
         "500_5000",
         "500 to 5,000"
        ],
        [
         "5000_50000",
         "5,000 to 50,000"
        ],
        [
         "gt_50000",
         "More than 50,000"
        ]
       ]
      }
     ]
    }
   ]
  },
  "cyber": {
   "tag": "SEC",
   "title": "Cyber security",
   "eyebrow": "Cyber security",
   "short": "Protect financial data",
   "intro": "Tell us what you want to protect. We will quote for the assessment, tools and training that fit your size.",
   "sections": [
    {
     "title": "Scope",
     "fields": [
      {
       "name": "services",
       "label": "Services needed (choose all that apply)",
       "type": "checkboxes",
       "required": true,
       "options": [
        [
         "assessment",
         "Security risk assessment"
        ],
        [
         "pentest",
         "Penetration testing"
        ],
        [
         "endpoint",
         "Antivirus and endpoint protection"
        ],
        [
         "firewall",
         "Firewall setup"
        ],
        [
         "backup",
         "Backup and disaster recovery"
        ],
        [
         "email",
         "Email security"
        ],
        [
         "policy",
         "Security policies"
        ],
        [
         "data_protection",
         "Data Protection Act compliance"
        ],
        [
         "training",
         "Staff awareness training"
        ],
        [
         "incident",
         "Help with an incident now"
        ]
       ]
      },
      {
       "name": "incident_details",
       "label": "What happened?",
       "type": "textarea",
       "max": 2000,
       "rows": 3,
       "show": "services~incident",
       "required": true
      },
      {
       "name": "devices",
       "label": "Computers and laptops",
       "type": "number",
       "min": 0,
       "max": 100000,
       "half": true,
       "required": true
      },
      {
       "name": "servers",
       "label": "Servers",
       "type": "number",
       "min": 0,
       "max": 10000,
       "half": true
      },
      {
       "name": "environment",
       "label": "Where are your systems?",
       "type": "checkboxes",
       "options": [
        [
         "office",
         "Office network"
        ],
        [
         "cloud",
         "Cloud (Microsoft 365, Google, AWS)"
        ],
        [
         "website",
         "Website or web app"
        ],
        [
         "remote",
         "Staff working remotely"
        ]
       ]
      }
     ]
    }
   ]
  },
  "dev": {
   "tag": "DEV",
   "title": "Software development",
   "eyebrow": "Software development",
   "short": "Applications built to your brief",
   "intro": "Share your brief. We will reply with a quotation, the main milestones and a timeline.",
   "sections": [
    {
     "title": "The project",
     "fields": [
      {
       "name": "project_type",
       "label": "What do you want built?",
       "type": "checkboxes",
       "required": true,
       "options": [
        [
         "website",
         "Website"
        ],
        [
         "web_app",
         "Web application"
        ],
        [
         "mobile_app",
         "Mobile app"
        ],
        [
         "api",
         "API or integration"
        ],
        [
         "desktop",
         "Desktop software"
        ],
        [
         "other",
         "Other"
        ]
       ]
      },
      {
       "name": "project_other",
       "label": "Describe it",
       "type": "text",
       "max": 200,
       "show": "project_type~other",
       "required": true
      },
      {
       "name": "stage",
       "label": "Where are you now?",
       "type": "select",
       "required": true,
       "half": true,
       "options": [
        [
         "idea",
         "Just an idea"
        ],
        [
         "requirements",
         "We have written requirements"
        ],
        [
         "design",
         "We have designs"
        ],
        [
         "existing",
         "Improving an existing product"
        ]
       ]
      },
      {
       "name": "design_ready",
       "label": "Do you need design (UI/UX)?",
       "type": "select",
       "half": true,
       "options": [
        [
         "yes",
         "Yes, design it for us"
        ],
        [
         "no",
         "No, we have designs"
        ]
       ]
      },
      {
       "name": "hosting",
       "label": "Hosting and domain",
       "type": "radio",
       "options": [
        [
         "need",
         "Arrange hosting for us"
        ],
        [
         "have",
         "We already have hosting"
        ],
        [
         "unsure",
         "Not sure"
        ]
       ]
      },
      {
       "name": "scope",
       "label": "Describe the main features",
       "type": "textarea",
       "max": 3000,
       "rows": 5,
       "required": true,
       "placeholder": "Who will use it and what they need to do."
      },
      {
       "name": "reference",
       "label": "Websites or apps you like (optional)",
       "type": "text",
       "max": 300
      }
     ]
    }
   ]
  },
  "net": {
   "tag": "NET",
   "title": "Networking",
   "eyebrow": "Networking",
   "short": "Offices, branches, secure links",
   "intro": "Tell us about the site. We will quote for the equipment, cabling and setup, and visit if a survey is needed.",
   "sections": [
    {
     "title": "The site",
     "fields": [
      {
       "name": "work",
       "label": "What do you need? (choose all that apply)",
       "type": "checkboxes",
       "required": true,
       "options": [
        [
         "new_office",
         "New office network"
        ],
        [
         "upgrade",
         "Upgrade an existing network"
        ],
        [
         "wifi",
         "Wi-Fi coverage"
        ],
        [
         "cabling",
         "Structured cabling"
        ],
        [
         "branch_link",
         "Link branches (VPN)"
        ],
        [
         "firewall",
         "Firewall and internet security"
        ],
        [
         "cctv",
         "CCTV"
        ],
        [
         "server_room",
         "Server room or rack"
        ],
        [
         "support",
         "Support contract"
        ]
       ]
      },
      {
       "name": "users",
       "label": "People using the network",
       "type": "number",
       "min": 1,
       "max": 100000,
       "required": true,
       "half": true
      },
      {
       "name": "points",
       "label": "Network points needed (approx.)",
       "type": "number",
       "min": 0,
       "max": 100000,
       "half": true
      },
      {
       "name": "sites",
       "label": "Number of sites or branches",
       "type": "number",
       "min": 1,
       "max": 1000,
       "half": true
      },
      {
       "name": "floors",
       "label": "Floors in the building",
       "type": "number",
       "min": 1,
       "max": 200,
       "half": true
      },
      {
       "name": "isp",
       "label": "Internet provider (if any)",
       "type": "text",
       "max": 120,
       "half": true
      },
      {
       "name": "site_visit",
       "label": "Can we visit the site to survey it?",
       "type": "select",
       "half": true,
       "options": [
        [
         "yes",
         "Yes"
        ],
        [
         "no",
         "No, quote from these details"
        ]
       ]
      }
     ]
    }
   ]
  },
  "adv": {
   "tag": "ADV",
   "title": "Enterprise advisory",
   "eyebrow": "Enterprise advisory",
   "short": "Technology planning for growing firms",
   "intro": "Tell us what you are planning. We will propose an engagement and send a quotation.",
   "sections": [
    {
     "title": "The engagement",
     "fields": [
      {
       "name": "areas",
       "label": "Areas you want help with (choose all that apply)",
       "type": "checkboxes",
       "required": true,
       "options": [
        [
         "strategy",
         "IT strategy and roadmap"
        ],
        [
         "digital",
         "Digital transformation"
        ],
        [
         "selection",
         "Choosing a system or vendor"
        ],
        [
         "it_audit",
         "IT audit or health check"
        ],
        [
         "budget",
         "IT budgeting"
        ],
        [
         "outsourcing",
         "Outsourcing and managed IT"
        ],
        [
         "governance",
         "IT policies and governance"
        ]
       ]
      },
      {
       "name": "staff",
       "label": "Number of staff",
       "type": "number",
       "min": 1,
       "max": 1000000,
       "half": true,
       "required": true
      },
      {
       "name": "engagement",
       "label": "Type of engagement",
       "type": "select",
       "half": true,
       "options": [
        [
         "one_off",
         "One-off project"
        ],
        [
         "retainer",
         "Monthly retainer"
        ],
        [
         "workshop",
         "Workshop or half-day session"
        ],
        [
         "unsure",
         "Not sure"
        ]
       ]
      },
      {
       "name": "goal",
       "label": "What do you want to achieve?",
       "type": "textarea",
       "max": 3000,
       "rows": 4,
       "required": true
      }
     ]
    }
   ]
  },
  "ict": {
   "tag": "ICT",
   "title": "ICT hardware",
   "eyebrow": "ICT hardware",
   "short": "Lenovo and Dell Technologies",
   "intro": "List the equipment you need. We will send a priced quotation from Lenovo and Dell, with delivery and warranty.",
   "sections": [
    {
     "title": "Equipment",
     "fields": [
      {
       "name": "categories",
       "label": "Equipment types (choose all that apply)",
       "type": "checkboxes",
       "required": true,
       "options": [
        [
         "laptops",
         "Laptops"
        ],
        [
         "desktops",
         "Desktops"
        ],
        [
         "workstations",
         "Workstations"
        ],
        [
         "servers",
         "Servers and storage"
        ],
        [
         "monitors",
         "Monitors"
        ],
        [
         "printers",
         "Printers"
        ],
        [
         "networking",
         "Networking equipment"
        ],
        [
         "ups",
         "UPS and power"
        ],
        [
         "accessories",
         "Accessories"
        ],
        [
         "software",
         "Software licences"
        ]
       ]
      },
      {
       "name": "brand",
       "label": "Preferred brand",
       "type": "radio",
       "options": [
        [
         "lenovo",
         "Lenovo"
        ],
        [
         "dell",
         "Dell"
        ],
        [
         "any",
         "Either, best value"
        ]
       ]
      },
      {
       "name": "items",
       "label": "Items",
       "type": "items",
       "required": true,
       "min_rows": 1,
       "max_rows": 40,
       "hint": "One line per item. Add the specification if you know it.",
       "columns": [
        {
         "name": "item",
         "label": "Item",
         "type": "text",
         "max": 160,
         "required": true,
         "placeholder": "e.g. Business laptop"
        },
        {
         "name": "spec",
         "label": "Specification (optional)",
         "type": "text",
         "max": 300,
         "placeholder": "e.g. Core i7, 16 GB RAM, 512 GB SSD"
        },
        {
         "name": "qty",
         "label": "Qty",
         "type": "number",
         "min": 1,
         "max": 100000,
         "required": true
        }
       ]
      }
     ]
    },
    {
     "title": "Delivery",
     "fields": [
      {
       "name": "delivery_location",
       "label": "Delivery location",
       "type": "text",
       "max": 200,
       "required": true,
       "half": true
      },
      {
       "name": "installation",
       "label": "Need setup or installation?",
       "type": "select",
       "half": true,
       "options": [
        [
         "yes",
         "Yes"
        ],
        [
         "no",
         "No, delivery only"
        ]
       ]
      },
      {
       "name": "payment",
       "label": "How will you pay?",
       "type": "select",
       "half": true,
       "options": [
        [
         "cash",
         "Cash or bank transfer"
        ],
        [
         "lpo",
         "LPO or credit terms"
        ],
        [
         "tender",
         "This is for a tender"
        ]
       ]
      },
      {
       "name": "warranty",
       "label": "Warranty needed",
       "type": "select",
       "half": true,
       "options": [
        [
         "standard",
         "Standard"
        ],
        [
         "1y",
         "Extended to 1 year"
        ],
        [
         "3y",
         "Extended to 3 years"
        ]
       ]
      }
     ]
    }
   ]
  }
 }
};
