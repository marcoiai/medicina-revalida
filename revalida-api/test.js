import OpenAI from "openai";

const openai = new OpenAI({
  apiKey: "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",
});

const response = openai.responses.create({
  model: "gpt-5.4-mini",
  input: "write a haiku about ai",
  store: true,
});

response.then((result) => console.log(result.output_text));
