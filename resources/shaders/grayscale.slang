#version 450

// Verification preset: an unambiguous full-screen desaturation, so a
// screenshot alone proves the filter chain ran (colors → luma gray).

#pragma stage vertex
layout(location = 0) in vec4 Position;
layout(location = 1) in vec2 TexCoord;
layout(location = 0) out vec2 vTexCoord;

layout(std140, set = 0, binding = 0) uniform UBO
{
    mat4 MVP;
} global;

void main()
{
    gl_Position = global.MVP * Position;
    vTexCoord = TexCoord;
}

#pragma stage fragment
layout(location = 0) in vec2 vTexCoord;
layout(location = 0) out vec4 FragColor;
layout(set = 0, binding = 2) uniform sampler2D Source;

void main()
{
    vec4 c = texture(Source, vTexCoord);
    float g = dot(c.rgb, vec3(0.299, 0.587, 0.114));
    FragColor = vec4(vec3(g), 1.0);
}
