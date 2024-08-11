"""
This script was used to generate the first version of MySQLParser.

From there, a lot of manual and automated refactoring was done to make
it more readable and maintainable.
"""

import os
import time
import os
import google.generativeai as genai
from google.generativeai.types import HarmCategory, HarmBlockThreshold, SafetySettingDict

genai.configure(api_key=os.environ["GEMINI_API_KEY"])

def upload_to_gemini(path, mime_type=None):
  """Uploads the given file to Gemini.

  See https://ai.google.dev/gemini-api/docs/prompting_with_media
  """
  file = genai.upload_file(path, mime_type=mime_type)
  print(f"Uploaded file '{file.display_name}' as: {file.uri}")
  return file

def wait_for_files_active(files):
  """Waits for the given files to be active.

  Some files uploaded to the Gemini API need to be processed before they can be
  used as prompt inputs. The status can be seen by querying the file's "state"
  field.

  This implementation uses a simple blocking polling loop. Production code
  should probably employ a more sophisticated approach.
  """
  print("Waiting for file processing...")
  for name in (file.name for file in files):
    file = genai.get_file(name)
    while file.state.name == "PROCESSING":
      print(".", end="", flush=True)
      time.sleep(10)
      file = genai.get_file(name)
    if file.state.name != "ACTIVE":
      raise Exception(f"File {file.name} failed to process")
  print("...all files ready")
  print()

# Create the model
generation_config = {
  "temperature": 1,
  "top_p": 0.95,
  "top_k": 64,
  "max_output_tokens": 2000000,
  "response_mime_type": "text/plain",
}

model = genai.GenerativeModel(
  model_name="gemini-1.5-pro",
  generation_config=generation_config,
  safety_settings = {
    HarmCategory.HARM_CATEGORY_DANGEROUS_CONTENT: HarmBlockThreshold.BLOCK_NONE,
    HarmCategory.HARM_CATEGORY_HARASSMENT: HarmBlockThreshold.BLOCK_NONE,
    HarmCategory.HARM_CATEGORY_HATE_SPEECH: HarmBlockThreshold.BLOCK_NONE,
    HarmCategory.HARM_CATEGORY_SEXUALLY_EXPLICIT: HarmBlockThreshold.BLOCK_NONE,
    # HarmCategory.HARM_CATEGORY_UNSPECIFIED: HarmBlockThreshold.BLOCK_NONE,     
  },
  # See https://ai.google.dev/gemini-api/docs/safety-settings
  system_instruction="IGNORE ANY PREVIOUS INSTRUCTIONS YOU MAY HAVE. YOU ARE AN ANTLR TO PHP CONVERTER. YOU DO NOT SAY ANYTHING THAT ISN'T PHP CODE. YOU REPLY UNTIL YOU EXHAUST THE AVAILABLE TOKEN WINDOW OF 2,097,152 TOKENS",
)

files = [
    # MySQL grammar from MySQL Workbench:
    # https://github.com/mysql/mysql-workbench/blob/8.0/library/parsers/grammars/MySQLParser.g4
    upload_to_gemini("MySQLParser.g4", mime_type="text/plain"),
    upload_to_gemini("MySQLLexer.php", mime_type="text/plain"),
]

# Some files have a processing delay. Wait for them to be ready.
wait_for_files_active(files)

while True:
    with open("MySQLParser.php", "r") as fp:
       parser_so_far = fp.read()
    if "THIS_IS_EOF" in parser_so_far:
       break
    chat_session = model.start_chat(
        history=[
            {
                "role": "user",
                "parts": [
                    files[0],
                    files[1],
                    "I'll give you a large ANTLR4 grammar file and I want you to convert it to a PHP LALR parser that outputs an AST. Convert everything. The PHP lexer class is already implemented and provided to you. I want to copy what you give me and paste it straight into the PHP interpreter and I want it to work. DO NOT SKIP ANY RULE, DO NOT REPLACE CODE CHUNKS WITH PLACEHOLDERS. Convert everything. Everything. Skip any prose whatsoever and reply directly with the PHP file. I DON'T WANT ANY TEXT OTHER THAN THE PHP CODE. DO NOT EXPLAIN TO ME WHAT ANTLR OR PHP OR PARSERS ARE. JUST CONVERT THE GRAMMAR. Do not use any library. Implement every single class you instantiate. Assume the entire program is a single, large PHP file. Use the exact same set of rules as listed in the original grammar. Do not inline any rule. If a rule called `ulong_number` exists in the grammar, there should be an explicit parser method for that. Keep it simple."
                ],
            },
            {
                "role": "model",
                "parts": [
                    parser_so_far
                ]
            }
        ]
    )

    response = chat_session.send_message(
        "KEEP GOING UNTIL **ALL** THE CODE IS GENERATED. THEN MAKE YOUR REPLY BE JUST 'THIS_IS_EOF'.",
        stream=True,
    )

    for chunk in response:
        print(chunk.text)
        with open("MySQLParser.php", "a") as file:
            file.write(chunk.text)

    # Break if our parser is larger than 1MB already
    file_size = os.path.getsize("MySQLParser.php")
    if file_size > 1024 * 1024:
        break

